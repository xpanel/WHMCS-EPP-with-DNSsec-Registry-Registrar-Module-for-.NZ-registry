WHMCS EPP with DNSsec Registry-Registrar Module for .NZ registry
========================

This module require an Accredited Registrar account for .NZ domains, it provide full functionality coverage for domains management and based on communication with Registry through Extensible Provisioning Protocol (EPP).

Requirements
------------

  * PHP 7.2 or higher;
  * WHMCS 7.7.0 or higher;

Installation
------------

Upload via FTP the 'nicch' directory to <whmcs_root>/modules/registrars/
Set write permissions for log file <whmcs_root>/modules/registrars/nicch/log/nicch.log

Generate .PEM certificate
-------------------------

create .pfx file
openssl pkcs12 -export -out certificate.pfx -inkey www.yourdomain.com.key -in yourdomain.com.crt -certfile CA_bundle.crt

```bash
$ openssl pkcs12 -export -out certificate.pfx -inkey /etc/pki/tls/private/www.yourdomain.com.key -in /etc/pki/tls/certs/yourdomain.com.crt -certfile /etc/pki/tls/certs/gd_bundle.crt
```

create PEM file

include PassPhrase

```bash
$ openssl pkcs12 -in certificate.pfx -out certificate.cer -nodes
```

or 
without PassPhrase 

```bash
$ openssl pkcs12 -in certificate.pfx -out certificate.cer
```

Upload certificates
-------------------

Copy certificate.cer to <whmcs_root>/modules/registrars/nicch/local_cert/certificate.cer
Copy www.yourdomain.com.key to <whmcs_root>/modules/registrars/nicch/local_pk/www.yourdomain.com.key
Copy a certificate authority file to <whmcs_root>/modules/registrars/nicch/cafile/


or you may use https://letsencrypt.org/
```bash
$ dnf install git net-tools
$ git clone https://github.com/certbot/certbot.git
$ cd certbot/
$ ./letsencrypt-auto --help
$ systemctl stop httpd
$ ./letsencrypt-auto certonly --standalone
```

Test connection with the server
-------------------------------

please make sure port 700 is not firewalled:

```bash
$ telnet srstestepp.srs.net.nz 700
$ openssl s_client -showcerts -connect srstestepp.srs.net.nz:700
```

command line for acceptable client certificate CA names

```bash
$ openssl s_client -showcerts -connect srstestepp.srs.net.nz:700 -CAfile gd_bundle.crt
```

command line to verify your own Certificate

```bash
$ openssl s_client -showcerts -connect srstestepp.srs.net.nz:700 -CAfile CA_bundle.crt -cert yourdomain.com.crt -key yourdomain.com.key
```
