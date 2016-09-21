#
# $Id$
#

Name:           PBREALPKG
Version:        PBVER
Release:        PBTAGPBSUF
License:        PBLIC
Group:          PBGRP
Url:            PBURL
Source:         PBSRC
BuildRequires:  php,php-phar,curl
Summary:        FOSSology-composer is a PHP phar composer tool

%description
PBDESC

%prep
# Should not be downloaded later but already in the git tree
# %setup -q

%build
# Should not be downloaded here but already in the git tree
curl -sS https://getcomposer.org/download/PBVERTARGET/composer.phar -o composer.phar
curl -sS https://github.com/composer/composer/blob/master/LICENSE -o LICENSE

%install
install -d -m 755 $RPM_BUILD_ROOT/%{_bindir}
install -m 755 composer.phar $RPM_BUILD_ROOT/%{_bindir}/composer

%files
%doc LICENSE
%{_bindir}/*

%changelog
PBLOG
