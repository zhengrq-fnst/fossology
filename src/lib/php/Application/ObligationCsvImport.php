<?php
/*
Copyright (C) 2014-2017, Siemens AG

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\Application;

use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Util\ArrayOperation;

class ObligationCsvImport {
  /** @var DbManager */
  protected $dbManager;
  /** @var string */
  protected $delimiter = ',';
  /** @var string */
  protected $enclosure = '"';
  /** @var null|array */
  protected $headrow = null;
  /** @var array */
  protected $alias = array(
      'type'=>array('type','Type'),
      'topic'=>array('topic','Obligation or Risk topic'),
      'text'=>array('text','Full Text'),
      'classification'=>array('classification','Classification'),
      'modifications'=>array('modifications','Apply on modified source code'),
      'comment'=>array('comment','Comment'),
      'licnames'=>array('licnames','Associated Licenses'),
      'candidatenames'=>array('candidatenames','Associated candidate Licenses')
    );

  public function __construct(DbManager $dbManager)
  {
    $this->dbManager = $dbManager;
    $this->obligationMap = $GLOBALS['container']->get('businessrules.obligationmap');
  }

  public function setDelimiter($delimiter=',')
  {
    $this->delimiter = substr($delimiter,0,1);
  }

  public function setEnclosure($enclosure='"')
  {
    $this->enclosure = substr($enclosure,0,1);
  }

  /**
   * @param string $filename
   * @return string message
   */
  public function handleFile($filename)
  {
    if (!is_file($filename) || ($handle = fopen($filename, 'r')) === FALSE) {
      return _('Internal error');
    }
    $cnt = -1;
    $msg = '';
    try
    {
      while(($row = fgetcsv($handle,0,$this->delimiter,$this->enclosure)) !== FALSE) {
        $log = $this->handleCsv($row);
        if (!empty($log))
        {
          $msg .= "$log\n";
        }
        $cnt++;
      }
      $msg .= _('Read csv').(": $cnt ")._('obligations');
    }
    catch(\Exception $e)
    {
      fclose($handle);
      return $msg .= _('Error while parsing file').': '.$e->getMessage();
    }
    fclose($handle);
    return $msg;
  }

  /**
   * @param array $row
   * @return string $log
   */
  private function handleCsv($row)
  {
    if($this->headrow===null)
    {
      $this->headrow = $this->handleHeadCsv($row);
      return 'head okay';
    }

    $mRow = array();
    foreach( array('type','topic','text','classification','modifications','comment','licnames','candidatenames') as $needle){
      $mRow[$needle] = $row[$this->headrow[$needle]];
    }

    return $this->handleCsvObligation($mRow);
  }

  private function handleHeadCsv($row)
  {
    $headrow = array();
    foreach( array('type','topic','text','classification','modifications','comment','licnames','candidatenames') as $needle){
      $col = ArrayOperation::multiSearch($this->alias[$needle], $row);
      if (false === $col)
      {
        throw new \Exception("Undetermined position of $needle");
      }
      $headrow[$needle] = $col;
    }
    return $headrow;
  }

  private function getKeyFromTopicAndText($row)
  {
    $req = array($row['topic'], $row['text']);
    $row = $this->dbManager->getSingleRow('SELECT ob_pk FROM obligation_ref WHERE ob_topic=$1 AND ob_md5=md5($2)',$req);
    return ($row === false) ? false : $row['ob_pk'];
  }

  private function compareLicList($exists, $listFromCsv, $candidate, $row)
  {
    $getList = $this->obligationMap->getLicenseList($exists, $candidate);
    $listFromDb = $this->reArrangeString($getList);
    $listFromCsv = $this->reArrangeString($listFromCsv);
    $diff = strcmp($listFromDb, $listFromCsv);
    return $diff;
  }

  private function reArrangeString($string)
  {
    $string = explode(";", $string);
    sort($string);
    $string = implode(",", $string);
    return $string;
  }

  private function clearListFromDb($exists, $candidate)
  {
    $licId = 0;
    $this->obligationMap->unassociateLicenseFromObligation($exists, $licId, $candidate);
    return true;
  }

  /**
   * @param array $row
   * @return string
   */
  private function handleCsvObligation($row)
  {
    /* @var $dbManager DbManager */
    $dbManager = $this->dbManager;
    $exists = $this->getKeyFromTopicAndText($row);
    $associatedLicenses = "";
    $candidateLicenses = "";
    $listFromCsv = "";
    $msg = "";
    if ($exists !== false)
    {
      $msg = "Obligation topic '$row[topic]' already exists in DB (id=".$exists."),";
      if ( $this->compareLicList($exists, $row['licnames'], false, $row) === 0 ) {
        $msg .=" No Changes in AssociateLicense";
      }
      else {
        $this->clearListFromDb($exists, false);
        if (!empty ($row['licnames'] ) ) {
          $associatedLicenses = $this->AssociateWithLicenses($row['licnames'], $exists, false);
        }
        $msg .=" Updated AssociatedLicense license";
      }
      if($this->compareLicList($exists, $row['candidatenames'], True, $row) === 0) {
        $msg .=" No Changes in CandidateLicense";
      }
      else {
        $this->clearListFromDb($exists, $listFromCsv);
        if(!empty($row['candidatenames'])) {
          $associatedLicenses = $this->AssociateWithLicenses($row['candidatenames'], $exists, True);
        }
        $msg .=" Updated CandidateLicense";
      }
      $this->updateOtherFields($exists, $row);
      return $msg."\n";
    }

    $stmtInsert = __METHOD__.'.insert';
    $dbManager->prepare($stmtInsert,'INSERT INTO obligation_ref (ob_type,ob_topic,ob_text,ob_classification,ob_modifications,ob_comment,ob_md5)'
            . ' VALUES ($1,$2,$3,$4,$5,$6,md5($3)) RETURNING ob_pk');
    $resi = $dbManager->execute($stmtInsert,array($row['type'],$row['topic'],$row['text'],$row['classification'],$row['modifications'],$row['comment']));
    $new = $dbManager->fetchArray($resi);
    $dbManager->freeResult($resi);

    if (!empty($row['licnames'])) {
      $associatedLicenses = $this->AssociateWithLicenses($row['licnames'], $new['ob_pk']);
    } 
    if (!empty($row['candidatenames'])) {
      $candidateLicenses = $this->AssociateWithLicenses($row['candidatenames'], $new['ob_pk'], True);
    }

    $message = "License association results for obligation '$row[topic]':\n";
    $message .= "$associatedLicenses";
    $message .= "$candidateLicenses";
    $message .= "Obligation with id=$new[ob_pk] was added successfully.\n";
    return $message;
  }

  /**
   * \brief Associate selected licenses to the obligation
   *
   * @param array   $licList - list of licenses to be associated
   *        int     $obPk - the id of the newly created obligation
   *        boolean $candidate - do we handle candidate licenses?
   * @return string the list of associated licences
   */
  function AssociateWithLicenses($licList, $obPk, $candidate=False)
  {
    $associatedLicenses = "";
    $message = "";

    $licenses = explode(";",$licList);
    foreach ($licenses as $license)
    {
      $licId = $this->obligationMap->getIdFromShortname($license, $candidate);
      if ($this->obligationMap->isLicenseAssociated($obPk, $licId, $candidate))
      {
        continue;
      }

      if (!empty($licId))
      {
        $this->obligationMap->associateLicenseWithObligation($obPk, $licId, $candidate);
        if ($associatedLicenses == "")
        {
          $associatedLicenses = "$license";
        } else {
          $associatedLicenses .= ";$license";
        }
      } else {
        $message .= "License $license could not be found in the DB.\n";
      }
    }

    if (!empty($associatedLicenses))
    {
      $message .= "$associatedLicenses were associated.\n";
    } else {
      $message .= "No ";
      $message .= $candidate ? "candidate": "";
      $message .= "licenses were associated.\n";
    }
    return $message;
  }

  function updateOtherFields($exists, $row)
  {
    $this->dbManager->getSingleRow('UPDATE obligation_ref SET ob_classification=$2, ob_modifications=$3, ob_comment=$4 where ob_pk=$1',
      array($exists, $row['classification'], $row['modifications'], $row['comment']),
      __METHOD__ . '.updateOtherOb');
  }
}
