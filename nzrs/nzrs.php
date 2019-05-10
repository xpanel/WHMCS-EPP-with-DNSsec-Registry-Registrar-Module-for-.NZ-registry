<?php
/**
*
* NOTICE OF LICENSE
*
*  @package   NZRS
*  @version   1.0.1
*  @author    Lilian Rudenco <info@xpanel.com>
*  @copyright 2019 Lilian Rudenco
*  @link      http://www.xpanel.com/
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*/

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Schema\Blueprint;

function _nzrs_error_handler($errno, $errstr, $errfile, $errline)
{
	if (!preg_match("/nzrs/i", $errfile)) {
		return true;
	}

	_nzrs_log("Error $errno:", "$errstr on line $errline in file $errfile");
}

set_error_handler('_nzrs_error_handler');
_nzrs_log('================= ' . date("Y-m-d H:i:s") . ' =================');

function nzrs_getConfigArray($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	_nzrs_create_table();
	_nzrs_create_column();
	$configarray = array(
		"EPPServer" => array(
			"FriendlyName" => "EPP Server",
			"Type" => "text",
			"Size" => "32",
			"Default" => "srstestepp.srs.net.nz",
			"Description" => "EPP Server Host."
		) ,
		"ServerPort" => array(
			"FriendlyName" => "Server Port",
			"Type" => "text",
			"Size" => "4",
			"Default" => "700",
			"Description" => "System port number 700 has been assigned by the IANA for mapping EPP onto TCP."
		) ,
		"clID" => array(
			"FriendlyName" => "Client ID",
			"Type" => "text",
			"Size" => "20",
			"Description" => "Client identifier."
		) ,
		"pw" => array(
			"FriendlyName" => "Password",
			"Type" => "password",
			"Size" => "20",
			"Description" => "Client's plain text password."
		) ,
		"RegistrarPrefix" => array(
			"FriendlyName" => "Registrar Prefix",
			"Type" => "text",
			"Size" => "4",
			"Description" => "Registry assigns each registrar a unique prefix with which that registrar must create contact IDs."
		)
	);
	return $configarray;
}

function _nzrs_startEppClient($params = array())
{
	$s = new nzrs_epp_client($params);
	$s->login($params['clID'], $params['pw'], $params['RegistrarPrefix']);
	return $s;
}

function nzrs_RegisterDomain($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-check-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<check>
	  <domain:check
		xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
		xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
	  </domain:check>
	</check>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->chkData;
		$reason = (string)$r->cd[0]->reason;
		if (!$reason) {
			$reason = 'Domain is not available';
		}

		if (0 == (string)$r->cd[0]->name->attributes()->avail) {
			throw new exception($r->cd[0]->name . ' ' . $reason);
		}

		$contacts = array();
		foreach(array(
			'registrant',
			'admin',
			'tech'
		) as $i => $contactType) {
			$from = $to = array();
			$from[] = "/{id}/";
			$id = strtolower($params['RegistrarPrefix'] . '' . $contactType . '' . $params['domainid']);
			$to[] = @htmlspecialchars($id);
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = @htmlspecialchars($params['RegistrarPrefix'] . '-contact-check-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<check>
	  <contact:check
		xmlns:contact="urn:ietf:params:xml:ns:contact-1.0"
		xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
		<contact:id>{id}</contact:id>
	  </contact:check>
	</check>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->chkData;

			//		$reason = (string)$r->cd[0]->reason;
			//		if (!$reason) {
			//			$reason = 'Contact is not available';
			//		}

			if (1 == (int)$r->cd[0]->id->attributes()->avail) {

				// contact:create

				$from = $to = array();
				$from[] = "/{id}/";
				$id = strtolower($params['RegistrarPrefix'] . '' . $contactType . '' . $params['domainid']);
				$to[] = @htmlspecialchars($id);
				$from[] = "/{name}/";
				$name = ($params['companyname'] ? $params['companyname'] : $params['fullname']);
                $to[] = @htmlspecialchars($name);
				$from[] = "/{street1}/";
				$to[] = @htmlspecialchars($params['address1']);
				$from[] = "/{street2}/";
				$to[] = @htmlspecialchars($params['address2']);
				$from[] = "/{city}/";
				$to[] = @htmlspecialchars($params['city']);
				$from[] = "/{state}/";
				$to[] = @htmlspecialchars($params['state']);
				$from[] = "/{postcode}/";
				$to[] = @htmlspecialchars($params['postcode']);
				$from[] = "/{country}/";
				$to[] = @htmlspecialchars($params['country']);
				$from[] = "/{phonenumber}/";
				$to[] = @htmlspecialchars($params['phonenumberformatted']);
				$from[] = "/{email}/";
				$to[] = @htmlspecialchars($params['email']);
				$from[] = "/{authInfo}/";
				$to[] = @htmlspecialchars($s->generateObjectPW());
				$from[] = "/{clTRID}/";
				$clTRID = str_replace('.', '', round(microtime(1) , 3));
				$to[] = @htmlspecialchars($params['RegistrarPrefix'] . '-contact-create-' . $clTRID);
				$from[] = "/<\w+:\w+>\s*<\/\w+:\w+>\s+/ims";
				$to[] = "";
				$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<create>
	  <contact:create
	   xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
		<contact:id>{id}</contact:id>
		<contact:postalInfo type="int">
		  <contact:name>{name}</contact:name>
		  <contact:addr>
			<contact:street>{street1}</contact:street>
			<contact:street>{street2}</contact:street>
			<contact:city>{city}</contact:city>
			<contact:sp>{state}</contact:sp>
			<contact:pc>{postcode}</contact:pc>
			<contact:cc>{country}</contact:cc>
		  </contact:addr>
		</contact:postalInfo>
		<contact:voice>{phonenumber}</contact:voice>
		<contact:fax></contact:fax>
		<contact:email>{email}</contact:email>
		<contact:authInfo>
		  <contact:pw>{authInfo}</contact:pw>
		</contact:authInfo>
	  </contact:create>
	</create>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
				$r = $s->write($xml, __FUNCTION__);
				$r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->creData;
				$contacts[$i + 1] = $r->id;
			}
			else {
				$contacts[$i + 1] = @htmlspecialchars($params['RegistrarPrefix'] . '' . $contactType . '' . $params['domainid']);
			}
		}

// aici nu e chiar bine, nu intotdeauna sunt 4 ns-uri

		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{period}/";
		$to[] = htmlspecialchars($params['regperiod']) * 12;
		$from[] = "/{ns1}/";
		$to[] = htmlspecialchars($params['ns1']);
		$from[] = "/{ns2}/";
		$to[] = htmlspecialchars($params['ns2']);
			$from[] = "/{ns3}/";
			$ns3 = $params['ns3'];
			$to[] = (empty($ns3) ? '' : "<domain:hostAttr><domain:hostName>{$ns3}</domain:hostName></domain:hostAttr>\n");
			$from[] = "/{ns4}/";
			$ns3 = $params['ns4'];
			$to[] = (empty($ns4) ? '' : "<domain:hostAttr><domain:hostName>{$ns4}</domain:hostName></domain:hostAttr>\n");
		$from[] = "/{cID_1}/";
		$to[] = htmlspecialchars($contacts[1]);
		$from[] = "/{cID_2}/";
		$to[] = htmlspecialchars($contacts[2]);
		$from[] = "/{cID_3}/";
		$to[] = htmlspecialchars($contacts[3]);
		$from[] = "/{cID_4}/";
		$to[] = htmlspecialchars($contacts[4]);
		$from[] = "/{authInfo}/";
		$to[] = htmlspecialchars($s->generateObjectPW());
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-create-' . $clTRID);
		$from[] = "/<\w+:\w+>\s*<\/\w+:\w+>\s+/ims";
		$to[] = "";
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
	<create>
	  <domain:create
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
		<domain:name>{domain}</domain:name>
		<domain:period unit="m">{period}</domain:period>
		<domain:ns>
		<domain:hostAttr>
		  <domain:hostName>{ns1}</domain:hostName>
		</domain:hostAttr>
		<domain:hostAttr>
		  <domain:hostName>{ns2}</domain:hostName>
		</domain:hostAttr>
		{ns3}
		{ns4}
		</domain:ns>
		<domain:registrant>{cID_1}</domain:registrant>
		<domain:contact type="admin">{cID_2}</domain:contact>
		<domain:contact type="tech">{cID_3}</domain:contact>
		<domain:authInfo>
		  <domain:pw>{authInfo}</domain:pw>
		</domain:authInfo>
	  </domain:create>
	</create>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_RenewDomain($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$expDate = (string)$r->exDate;
		$expDate = preg_replace("/^(\d+\-\d+\-\d+)\D.*$/", "$1", $expDate);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{regperiod}/";
		$to[] = htmlspecialchars($params['regperiod']);
		$from[] = "/{expDate}/";
		$to[] = htmlspecialchars($expDate);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-renew-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
	<renew>
	  <domain:renew
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
		<domain:name>{domain}</domain:name>
		<domain:curExpDate>{expDate}</domain:curExpDate>
		<domain:period unit="y">{regperiod}</domain:period>
	  </domain:renew>
	</renew>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->creData;
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_TransferDomain($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
//		$from[] = "/{years}/";
//		$to[] = htmlspecialchars($params['regperiod']);
		$from[] = "/{authInfo_pw}/";
		$to[] = htmlspecialchars($params['transfersecret']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-transfer-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
	<transfer op="request">
	  <domain:transfer
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
		<domain:name>{domain}</domain:name>
		<domain:authInfo>
		  <domain:pw>{authInfo_pw}</domain:pw>
		</domain:authInfo>
	  </domain:transfer>
	</transfer>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
//		<domain:period unit="y">{years}</domain:period>

		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->trnData;
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_GetNameservers($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$i = 0;
		foreach($r->ns as $ns) {
			foreach($ns->hostAttr as $hostAttr) {
				$i++;
				$return["ns{$i}"] = (string)$hostAttr->hostName;
			}
		}

		$status = array();
		Capsule::table('epp_domain_status')->where('domain_id', '=', $params['domainid'])->delete();
		foreach($r->status as $e) {
			$st = (string)$e->attributes()->s;
			if ($st == 'pendingDelete') {
				$updatedDomainStatus = Capsule::table('tbldomains')->where('id', $params['domainid'])->update(['status' => 'Cancelled']);
			}

			Capsule::table('epp_domain_status')->insert(['domain_id' => $params['domainid'], 'status' => $st]);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_SaveNameservers($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$add = $rem = array();
		$i = 0;
		foreach($r->ns as $ns) {
			foreach($ns->hostAttr as $hostAttr) {
				$i++;
				$ns = (string)$hostAttr->hostName;
				if (!$ns) {
					continue;
				}

				$rem["ns{$i}"] = $ns;
			}
		}

		foreach($params as $k => $v) {
			if (!$v) {
				continue;
			}

			if (!preg_match("/^ns\d$/i", $k)) {
				continue;
			}

//			$v = strtoupper($v);
			if ($k0 = array_search($v, $rem)) {
				unset($rem[$k0]);
			}
			else {
				$add[$k] = $v;
			}
		}

		if (!empty($add) || !empty($rem)) {
			$from = $to = array();
			$text = '';
			foreach($add as $k => $v) {
				$text.= '<domain:hostAttr><domain:hostName>' . $v . '</domain:hostName></domain:hostAttr>' . "\n";
			}

			$from[] = "/{add}/";
			$to[] = (empty($text) ? '' : "<domain:add><domain:ns>{$text}</domain:ns></domain:add>\n");
			$text = '';
			foreach($rem as $k => $v) {
				$text.= '<domain:hostAttr><domain:hostName>' . $v . '</domain:hostName></domain:hostAttr>' . "\n";
			}

			$from[] = "/{rem}/";
			$to[] = (empty($text) ? '' : "<domain:rem><domain:ns>{$text}</domain:ns></domain:rem>\n");
			$from[] = "/{domain}/";
			$to[] = htmlspecialchars($params['domainname']);
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-update-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>

	{add}

	{rem}

	  </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_GetContactDetails($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$dcontact = array();
		$dcontact['registrant'] = (string)$r->registrant;
		foreach($r->contact as $e) {
			$type = (string)$e->attributes()->type;
			$dcontact[$type] = (string)$e;
		}

		$contact = array();
		foreach($dcontact as $id) {
			if (isset($contact[$id])) {
				continue;
			}

			$from = $to = array();
			$from[] = "/{id}/";
			$to[] = htmlspecialchars($id);
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-contact-info-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <contact:info
	   xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
		<contact:id>{id}</contact:id>
	  </contact:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
			$r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->infData[0];
			$contact[$id] = array();
			$c = & $contact[$id];
			foreach($r->postalInfo as $e) {
				$c["Name"] = (string)$e->name;
				for ($i = 0; $i <= 1; $i++) {
					$c["Street " . ($i + 1) ] = (string)$e->addr->street[$i];
				}

				if (empty($c["Street 3"])) {
					unset($c["street3"]);
				}

				$c["City"] = (string)$e->addr->city;
				$c["State or Province"] = (string)$e->addr->sp;
				$c["Postal Code"] = (string)$e->addr->pc;
				$c["Country Code"] = (string)$e->addr->cc;
				break;
			}

			$c["Phone"] = (string)$r->voice;
			$c["Fax"] = (string)$r->fax;
			$c["Email"] = (string)$r->email;
		}

		foreach($dcontact as $type => $id) {
			if ($type == 'registrant') {
				$type = 'Registrant';
			}
			elseif ($type == 'admin') {
				$type = 'Administrator';
			}
			elseif ($type == 'tech') {
				$type = 'Technical';
			}
			elseif ($type == 'billing') {
				$type = 'Billing';
			}
			else {
				continue;
			}

			$return[$type] = $contact[$id];
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_SaveContactDetails($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$dcontact = array();
		$dcontact['registrant'] = (string)$r->registrant;
		foreach($r->contact as $e) {
			$type = (string)$e->attributes()->type;
			$dcontact[$type] = (string)$e;
		}

		foreach($dcontact as $type => $id) {
			$a = array();
			if ($type == 'registrant') {
				$a = $params['contactdetails']['Registrant'];
			}
			elseif ($type == 'admin') {
				$a = $params['contactdetails']['Administrator'];
			}
			elseif ($type == 'tech') {
				$a = $params['contactdetails']['Technical'];
			}
			elseif ($type == 'billing') {
				$a = $params['contactdetails']['Billing'];
			}

			if (empty($a)) {
				continue;
			}

			$from = $to = array();
			$from[] = "/{id}/";
			$to[] = htmlspecialchars($id);
               $from[] = "/{name}/";
               $name = ($a['Name'] ? $a['Name'] : $a['Full Name']);
               if ($a['Organisation Name']) { $name = $a['Organisation Name']; }
               $to[] = htmlspecialchars($name);

               $from[] = "/{street1}/";
               $street1 = ($a['Street 1'] ? $a['Street 1'] : $a['Address 1']);
               $to[] = htmlspecialchars($street1);

               $from[] = "/{street2}/";
               $street2 = ($a['Street 2'] ? $a['Street 2'] : $a['Address 2']);
               $to[] = htmlspecialchars($street2);

               $from[] = "/{city}/";
               $to[] = htmlspecialchars($a['City']);

               $from[] = "/{sp}/";
               $sp = ($a['State or Province'] ? $a['State or Province'] : $a['State']);
               $to[] = htmlspecialchars($sp);

               $from[] = "/{pc}/";
               $pc = ($a['Postal Code'] ? $a['Postal Code'] : $a['Postcode']);
               $to[] = htmlspecialchars($pc);

               $from[] = "/{cc}/";
               $cc = ($a['Country Code'] ? $a['Country Code'] : $a['Country']);
               $to[] = htmlspecialchars($cc);

               $from[] = "/{voice}/";
               $to[] = htmlspecialchars($a['Phone']);

               $from[] = "/{fax}/";
               $to[] = htmlspecialchars($a['Fax']);

               $from[] = "/{email}/";
               $to[] = htmlspecialchars($a['Email']);
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-contact-chg-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
		<contact:id>{id}</contact:id>
		<contact:chg>
		  <contact:postalInfo type="int">
			<contact:name>{name}</contact:name>
			<contact:addr>
			  <contact:street>{street1}</contact:street>
			  <contact:street>{street2}</contact:street>
			  <contact:city>{city}</contact:city>
			  <contact:sp>{sp}</contact:sp>
			  <contact:pc>{pc}</contact:pc>
			  <contact:cc>{cc}</contact:cc>
			</contact:addr>
		  </contact:postalInfo>
		  <contact:voice>{voice}</contact:voice>
		  <contact:fax>{fax}</contact:fax>
		  <contact:email>{email}</contact:email>
		</contact:chg>
	  </contact:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_IDProtectToggle($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$dcontact = array();
		$dcontact['registrant'] = (string)$r->registrant;
		foreach($r->contact as $e) {
			$type = (string)$e->attributes()->type;
			$dcontact[$type] = (string)$e;
		}

		$contact = array();
		foreach($dcontact as $id) {
			if (isset($contact[$id])) {
				continue;
			}

			$from = $to = array();
			$from[] = "/{id}/";
			$to[] = htmlspecialchars($id);

			$from[] = "/{flag}/";
			$to[] = ($params['protectenable'] ? 0 : 1);

			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-contact-update-' . $clTRID);

			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <contact:update xmlns:contact="urn:ietf:params:xml:ns:contact-1.0" xsi:schemaLocation="urn:ietf:params:xml:ns:contact-1.0 contact-1.0.xsd">
		<contact:id>{id}</contact:id>
		<contact:chg>
          <contact:disclose flag="{flag}">
			<contact:addr type="int"/>
			<contact:voice/>
			<contact:fax/>
          </contact:disclose>
		</contact:chg>
	  </contact:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_GetEPPCode($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);

// facem new UDAI, generam unul nou, el va aparea in poll ca ultimul mesaj teoretic
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-update-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
        <domain:chg>
          <domain:authInfo>
            <domain:pw></domain:pw>
          </domain:authInfo>
        </domain:chg>
	  </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);

		for ($xcount = 0; $xcount <= 100; $xcount++) {
			// facem poll req citim poll apoi facem acq
			$break = 0;
			$from = $to = array();
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-poll-req-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<poll op="req"/>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);

		if ((int)$r->response->result->attributes()->code == 1300) {
			// Command completed successfully; no messages $r->response->result->msg;
			$break = 1;
		}
		else {
			// sunt mesaje
			$msgQ_id = (string)$r->response->msgQ->attributes()->id;
			// $msgQ_count = (int)$r->response->msgQ->attributes()->count;
			$msg = (string)$r->response->msgQ->msg;

			if ($msg == 'New UDAI') {
			  	// citim valoarea lui, doar daca domeniul ne apartine noua
		  		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		  		$name = (string)$r->name;
		  		if ($name == htmlspecialchars($params['domainname'])) {
					$eppcode = (string)$r->authInfo->pw;
		  		}
			}

			// vedem daca mesajul actual este si cel necesar daca nu atunci facem un loop
			$from = $to = array();
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-poll-ack-' . $clTRID);
			$from[] = "/{msgID}/";
			$to[] = htmlspecialchars($msgQ_id);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
	<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
		 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
		 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
	  <command>
		<poll msgID="{msgID}" op="ack"/>
		<clTRID>{clTRID}</clTRID>
	  </command>
	</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}

	if ($break) {
		// iesim din loop, am citit toate mesajele din poll
		break;
	}
}

// If EPP Code is returned, return it for display to the end user
return array('eppcode' => $eppcode);

// facem domain info ca sa obtinem contact id type admin
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		//$eppcode = (string)$r->authInfo->pw;
		$contact_id = (string)$r->registrant; 		// aici nu e corect, trebuie admin, dar e mai greu de obtinut din xml

// facem contact info ca sa gasim email
		$from = $to = array();
		$from[] = "/{id}/";
		$to[] = htmlspecialchars($contact_id);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-contact-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <contact:info
	   xmlns:contact="urn:ietf:params:xml:ns:contact-1.0">
		<contact:id>{id}</contact:id>
	  </contact:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:contact-1.0')->infData[0];
		$toEmail = (string)$r->email;


// un double check aici pentru linistea noastra

// This command is used to retrieve information associated with a domain name.
// The command can also be used to validate the UDAI by passing the UDAI in the authinfo element.
// If the UDAI is valid domain details are returned otherwise the request is rejected with a "2202" return code.
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{pw}/";
		$to[] = $eppcode;
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
        <domain:authInfo>
          <domain:pw>{pw}</domain:pw>
        </domain:authInfo>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);

//---------------------------------------------	avem de toate, trimitem email

		global $CONFIG;
		$mail = new PHPMailer();
		$mail->From = $CONFIG['SystemEmailsFromEmail'];
		$mail->FromName = $CONFIG['SystemEmailsFromName'];
		$mail->Subject = strtoupper($params['domainname']) . ' >> Information You Requested ';
		$mail->CharSet = $CONFIG['Charset'];
		if ($CONFIG['MailType'] == 'mail') {
			$mail->Mailer = 'mail';
		}
		else {
			$mail->IsSMTP();
			$mail->Host = $CONFIG['SMTPHost'];
			$mail->Port = $CONFIG['SMTPPort'];
			$mail->Hostname = $_SERVER['SERVER_NAME'];
			if ($CONFIG['SMTPSSL']) {
				$mail->SMTPSecure = $CONFIG['SMTPSSL'];
			}

			if ($CONFIG['SMTPUsername']) {
				$mail->SMTPAuth = true;
				$mail->Username = $CONFIG['SMTPUsername'];
				$mail->Password = decrypt($CONFIG['SMTPPassword']);
			}

			$mail->Sender = $CONFIG['Email'];
		}

		$mail->AddAddress($toEmail);
		$message = "
=============================================
DOMAIN INFORMATION YOU REQUESTED
=============================================

The authorization information you requested is as follows:

Domain Name: " . strtoupper($params['domainname']) . "

Authorization Info: " . $eppcode . "

Regards,
" . $CONFIG['CompanyName'] . "
" . $CONFIG['Domain'] . "

--------------------------------------------------------------------------------
Copyright (C) " . date('Y') . " " . $CONFIG['CompanyName'] . " All rights reserved.
";
		$mail->Body = nl2br(htmlspecialchars($message));
		$mail->AltBody = $message; //text
		if (!$mail->Send()) {
			_nzrs_log(__FUNCTION__, $mail);
			throw new exception("There has been an error sending the message. " . $mail->ErrorInfo);
		}

		$mail->ClearAddresses();
	}

	catch(phpmailerException $e) {
		$return = array(
			'error' => "There has been an error sending the message. " . $e->getMessage()
		);
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_RegisterNameserver($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{host}/";
		$to[] = htmlspecialchars($params['nameserver']);
		$from[] = "/{ip}/";
		$to[] = htmlspecialchars($params['ipaddress']);
		$from[] = "/{v}/";
		$to[] = (preg_match("/:/", $params['ipaddress']) ? 'v6' : 'v4');
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-host-create-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
        	<domain:add>
          <domain:ns>
            <domain:hostAttr>
              <domain:hostName>{host}</domain:hostName>
              <domain:hostAddr ip="{v}">{ip}</domain:hostAddr>
            </domain:hostAttr>
          </domain:ns>
           	</domain:add>
	  </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_ModifyNameserver($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{host}/";
		$to[] = htmlspecialchars($params['nameserver']);
		$from[] = "/{ip1}/";
		$to[] = htmlspecialchars($params['currentipaddress']);
		$from[] = "/{v1}/";
		$to[] = (preg_match("/:/", $params['currentipaddress']) ? 'v6' : 'v4');
		$from[] = "/{ip2}/";
		$to[] = htmlspecialchars($params['newipaddress']);
		$from[] = "/{v2}/";
		$to[] = (preg_match("/:/", $params['newipaddress']) ? 'v6' : 'v4');
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-host-update-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
        	<domain:add>
          <domain:ns>
            <domain:hostAttr>
              <domain:hostName>{host}</domain:hostName>
              <domain:hostAddr ip="{v2}">{ip2}</domain:hostAddr>
            </domain:hostAttr>
          </domain:ns>
           	</domain:add>
        	<domain:rem>
          <domain:ns>
            <domain:hostAttr>
              <domain:hostName>{host}</domain:hostName>
              <domain:hostAddr ip="{v1}">{ip1}</domain:hostAddr>
            </domain:hostAttr>
          </domain:ns>
           	</domain:rem>
	  </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_DeleteNameserver($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);		
		$from[] = "/{host}/";
		$to[] = htmlspecialchars($params['nameserver']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-host-delete-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
    <update>
      <domain:update xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
        <domain:name>{domain}</domain:name>
        <domain:rem>
          <domain:ns>
            <domain:hostAttr>
              <domain:hostName>{host}</domain:hostName>
            </domain:hostAttr>
          </domain:ns>
        </domain:rem>
      </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_RequestDelete($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-delete-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
	<delete>
	  <domain:delete
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0">
		<domain:name>{domain}</domain:name>
	  </domain:delete>
	</delete>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

/**
 * Display the 'DNSSECDSRecords' screen for a domain.
 * @param array $params Parameters from WHMCS
 * @return array
 */
function nzrs_manageDNSSECDSRecords($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);

		if (isset($_POST['command']) && ($_POST['command'] === 'secDNSadd')) {
			$keyTag = $_POST['keyTag'];
			$alg = $_POST['alg'];
			$digestType = $_POST['digestType'];
			$digest = $_POST['digest'];

			$from = $to = array();
			$from[] = "/{domainname}/";
			$to[] = htmlspecialchars($params['domainname']);

			$from[] = "/{keyTag}/";
			$to[] = htmlspecialchars($keyTag);

			$from[] = "/{alg}/";
			$to[] = htmlspecialchars($alg);

			$from[] = "/{digestType}/";
			$to[] = htmlspecialchars($digestType);

			$from[] = "/{digest}/";
			$to[] = htmlspecialchars($digest);

			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-update-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domainname}</domain:name>
	  </domain:update>
	</update>
    <extension>
      <secDNS:update xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
        <secDNS:add>
          <secDNS:dsData>
            <secDNS:keyTag>{keyTag}</secDNS:keyTag>
            <secDNS:alg>{alg}</secDNS:alg>
            <secDNS:digestType>{digestType}</secDNS:digestType>
            <secDNS:digest>{digest}</secDNS:digest>
          </secDNS:dsData>
        </secDNS:add>
      </secDNS:update>
    </extension>	
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}

		if (isset($_POST['command']) && ($_POST['command'] === 'secDNSrem')) {
			$keyTag = $_POST['keyTag'];
			$alg = $_POST['alg'];
			$digestType = $_POST['digestType'];
			$digest = $_POST['digest'];

			$from = $to = array();
			$from[] = "/{domainname}/";
			$to[] = htmlspecialchars($params['domainname']);

			$from[] = "/{keyTag}/";
			$to[] = htmlspecialchars($keyTag);

			$from[] = "/{alg}/";
			$to[] = htmlspecialchars($alg);

			$from[] = "/{digestType}/";
			$to[] = htmlspecialchars($digestType);

			$from[] = "/{digest}/";
			$to[] = htmlspecialchars($digest);

			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-update-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domainname}</domain:name>
	  </domain:update>
	</update>
    <extension>
      <secDNS:update xmlns:secDNS="urn:ietf:params:xml:ns:secDNS-1.1">
        <secDNS:rem>
          <secDNS:dsData>
            <secDNS:keyTag>{keyTag}</secDNS:keyTag>
            <secDNS:alg>{alg}</secDNS:alg>
            <secDNS:digestType>{digestType}</secDNS:digestType>
            <secDNS:digest>{digest}</secDNS:digest>
          </secDNS:dsData>
        </secDNS:rem>
      </secDNS:update>
    </extension>	
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}

		$from = $to = array();
		$from[] = "/{domainname}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domainname}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);

		$secDNSdsData = array();
		if ($r->response->extension && $r->response->extension->children('urn:ietf:params:xml:ns:secDNS-1.1')->infData) {
			$DSRecords = 'YES';
			$i=0;
			$r = $r->response->extension->children('urn:ietf:params:xml:ns:secDNS-1.1')->infData;
			foreach($r->dsData as $dsData) {
				$i++;
				$secDNSdsData[$i]["domainid"] = (int)$params['domainid'];
				$secDNSdsData[$i]["keyTag"] = (string)$dsData->keyTag;
				$secDNSdsData[$i]["alg"] = (int)$dsData->alg;
				$secDNSdsData[$i]["digestType"] = (int)$dsData->digestType;
				$secDNSdsData[$i]["digest"] = (string)$dsData->digest;
			}
		}
		else {
			$DSRecords = "You don't have any DS records";
		}

		$return = array(
			'templatefile' => 'manageDNSSECDSRecords',
			'requirelogin' => true,
			'vars' => array(
				'DSRecords' => $DSRecords,
				'DSRecordslist' => $secDNSdsData
			)
		);
	}

	catch(exception $e) {
		$return = array(
			'templatefile' => 'manageDNSSECDSRecords',
			'requirelogin' => true,
			'vars' => array(
				'error' => $e->getMessage()
			)
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

/**
 * Buttons for the client area for custom functions.
 * @return array
 */
function nzrs_ClientAreaCustomButtonArray()
{
	$buttonarray = array(
		Lang::Trans('Manage DNSSEC DS Records') => 'manageDNSSECDSRecords'
	);
	
	return $buttonarray;
}

function nzrs_AdminCustomButtonArray($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$domainid = $params['domainid'];

	// $domain = Capsule::table('tbldomains')->where('id', $domainid)->first();

	$domain = Capsule::table('epp_domain_status')->where('domain_id', '=', $domainid)->where('status', '=', 'clientHold')->first();
	if (isset($domain->status)) {
		return array(
			"Unhold Domain" => "UnHoldDomain"
		);
	}
	else {
		return array(
			"Put Domain On Hold" => "OnHoldDomain"
		);
	}
}

function nzrs_OnHoldDomain($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$status = array();
		$existing_status = 'ok';
		foreach($r->status as $e) {
			$st = (string)$e->attributes()->s;
			if ($st == 'clientHold') {
				$existing_status = 'clientHold';
				break;
			}

			if ($st == 'serverHold') {
				$existing_status = 'serverHold';
				break;
			}
		}

		if ($existing_status == 'ok') {
			$from[] = "/{domain}/";
			$to[] = htmlspecialchars($params['domainname']);
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-update-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
        	<domain:add>
             	<domain:status s="clientHold" lang="en">clientHold</domain:status>
           	</domain:add>
	  </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_UnHoldDomain($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domainname']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$status = array();
		$existing_status = 'ok';
		foreach($r->status as $e) {
			$st = (string)$e->attributes()->s;
			if ($st == 'clientHold') {
				$existing_status = 'clientHold';
				break;
			}
		}

		if ($existing_status == 'clientHold') {
			$from[] = "/{domain}/";
			$to[] = htmlspecialchars($params['domainname']);
			$from[] = "/{clTRID}/";
			$clTRID = str_replace('.', '', round(microtime(1) , 3));
			$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-update-' . $clTRID);
			$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<update>
	  <domain:update
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
        	<domain:rem>
             	<domain:status s="clientHold" lang="en">clientHold</domain:status>
           	</domain:rem>
	  </domain:update>
	</update>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
			$r = $s->write($xml, __FUNCTION__);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_TransferSyncDISABLED($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domain']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-transfer-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<transfer op="query">
	  <domain:transfer
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name>{domain}</domain:name>
	  </domain:transfer>
	</transfer>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->trnData;
		$trStatus = (string)$r->trStatus;
		$expDate = (string)$r->exDate;
		$updatedDomainTrStatus = Capsule::table('tbldomains')->where('id', $params['domainid'])->update(['trstatus' => $trStatus]);
		switch ($trStatus) {
		case 'pending':
			$return['completed'] = false;
			break;

		case 'clientApproved':
		case 'serverApproved':
			$return['completed'] = true;
			$return['expirydate'] = date('Y-m-d', is_numeric($expDate) ? $expDate : strtotime($expDate));
			break;

		case 'clientRejected':
		case 'clientCancelled':
		case 'serverCancelled':
			$return['failed'] = true;
			$return['reason'] = $trStatus;
			break;

		default:
			$return = array(
				'error' => sprintf('invalid transfer status: %s', $trStatus)
			);
			break;
		}

		return $return;
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

function nzrs_Sync($params = array())
{
	_nzrs_log(__FUNCTION__, $params);
	$return = array();
	try {
		$s = _nzrs_startEppClient($params);
		$from = $to = array();
		$from[] = "/{domain}/";
		$to[] = htmlspecialchars($params['domain']);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($params['RegistrarPrefix'] . '-domain-info-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	 xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	 xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
  <command>
	<info>
	  <domain:info
	   xmlns:domain="urn:ietf:params:xml:ns:domain-1.0"
	   xsi:schemaLocation="urn:ietf:params:xml:ns:domain-1.0 domain-1.0.xsd">
		<domain:name hosts="all">{domain}</domain:name>
	  </domain:info>
	</info>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $s->write($xml, __FUNCTION__);
		$r = $r->response->resData->children('urn:ietf:params:xml:ns:domain-1.0')->infData;
		$expDate = (string)$r->exDate;
		$timestamp = strtotime($expDate);
		if ($timestamp === false) {
			return array(
				'error' => 'Empty renewal date for domain: ' . $params['domainname']
			);
		}

		$expDate = preg_replace("/^(\d+\-\d+\-\d+)\D.*$/", "$1", $expDate);
		if ($timestamp < time()) {
			return array(
				'expirydate' => $expDate,
				'expired' => true
			);
		}
		else {
			return array(
				'expirydate' => $expDate,
				'active' => true
			);
		}
	}

	catch(exception $e) {
		$return = array(
			'error' => $e->getMessage()
		);
	}

	if (!empty($s)) {
		$s->logout($params['RegistrarPrefix']);
	}

	return $return;
}

class nzrs_epp_client

{
	var $socket;
	var $isLogined = false;
	var $params;
	function __construct($params)
	{
		$this->params = $params;
		$host = $params['EPPServer'];
		$port = $params['ServerPort'];
		if ($host) {
			$this->connect($host, $port);
		}
	}

	function connect($host, $port = 700, $timeout = 30)
	{
		ini_set('display_errors', true);
		error_reporting(E_ALL);

		// echo '<pre>';print_r($host);
		// print_r($this->params);
		// exit;

		if ($host != $this->params['EPPServer']) {
			throw new exception("Unknown EPP server '$host'");
		}

		$opts = array(
			'ssl' => array(
				'verify_peer' => false,
				'capath' => __DIR__ . "/capath/",
				'local_cert' => __DIR__ . "/localcert/nzrs.crt",
				'local_pk' => __DIR__ . "/localpk/nzrs.key",
				'allow_self_signed' => true,
				'crypto_method' => STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT
			)
		);
		$context = stream_context_create($opts);
		$this->socket = stream_socket_client("tlsv1.2://{$host}:{$port}", $errno, $errmsg, $timeout, STREAM_CLIENT_CONNECT, $context);
		if (!$this->socket) {
			throw new exception("Cannot connect to server '{$host}': {$errmsg}");
		}

		return $this->read();
	}

	function login($login, $pwd, $prefix)
	{
		$from = $to = array();
		$from[] = "/{login}/";
		$to[] = htmlspecialchars($login);
		$from[] = "/{pwd}/";
		$to[] = htmlspecialchars($pwd);
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($prefix . '-login-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0">
  <command>
	<login>
	  <clID>{login}</clID>
	  <pw>{pwd}</pw>
	  <options>
		<version>1.0</version>
		<lang>en</lang>
	  </options>
	  <svcs>
        <objURI>urn:ietf:params:xml:ns:domain-1.0</objURI>
        <objURI>urn:ietf:params:xml:ns:contact-1.0</objURI>
        <svcExtension>
          <extURI>urn:ietf:params:xml:ns:secDNS-1.1</extURI>
        </svcExtension>
	  </svcs>
	</login>
	<clTRID>{clTRID}</clTRID>
  </command>
</epp>');
		$r = $this->write($xml, __FUNCTION__);
		$this->isLogined = true;
		return true;
	}

	function logout($prefix)
	{
		if (!$this->isLogined) {
			return true;
		}

		$from = $to = array();
		$from[] = "/{clTRID}/";
		$clTRID = str_replace('.', '', round(microtime(1) , 3));
		$to[] = htmlspecialchars($prefix . '-logout-' . $clTRID);
		$xml = preg_replace($from, $to, '<?xml version="1.0" encoding="UTF-8" standalone="no"?>
<epp xmlns="urn:ietf:params:xml:ns:epp-1.0"
	  xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
	  xsi:schemaLocation="urn:ietf:params:xml:ns:epp-1.0 epp-1.0.xsd">
   <command>
	 <logout/>
	 <clTRID>{clTRID}</clTRID>
   </command>
</epp>');
		$r = $this->write($xml, __FUNCTION__);
		$this->isLogined = false;
		return true;
	}

	function read()
	{
		_nzrs_log('================= read-this =================', $this);
		if (feof($this->socket)) {
			throw new exception('Connection appears to have closed.');
		}

		$hdr = @fread($this->socket, 4);
		if (empty($hdr)) {
			throw new exception("Error reading from server: $php_errormsg");
		}

		$unpacked = unpack('N', $hdr);
		$xml = fread($this->socket, ($unpacked[1] - 4));
		$xml = preg_replace("/></", ">\n<", $xml);
		_nzrs_log('================= read =================', $xml);
		return $xml;
	}

	function write($xml, $action = 'Unknown')
	{
		_nzrs_log('================= send-this =================', $this);
		_nzrs_log('================= send =================', $xml);
		@fwrite($this->socket, pack('N', (strlen($xml) + 4)) . $xml);
		$r = $this->read();
		_nzrs_modulelog($xml, $r, $action);
		$r = new SimpleXMLElement($r);
		if ($r->response->result->attributes()->code >= 2000) {
			throw new exception($r->response->result->msg);
		}
		return $r;
	}

	function disconnect()
	{
		return @fclose($this->socket);
	}

	function generateObjectPW($objType = 'none')
	{
		$result = '';
		$chars = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890><!=+-";
		$minLength = 13;
		$maxLength = 13;
		$length = mt_rand($minLength, $maxLength);
		while ($length--) {
			$result.= $chars[mt_rand(1, strlen($chars) - 1) ];
		}

		return 'aA1' . $result;
	}
}

function _nzrs_modulelog($send, $responsedata, $action)
{
	$from = $to = array();
	$from[] = "/<clID>[^<]*<\/clID>/i";
	$to[] = '<clID>Not disclosed clID</clID>';
	$from[] = "/<pw>[^<]*<\/pw>/i";
	$to[] = '<pw>Not disclosed pw</pw>';
	$sendforlog = preg_replace($from, $to, $send);
	logModuleCall('nzrs',$action,$sendforlog,$responsedata);
}

function _nzrs_log($func, $params = false)
{

	// comment line below to see logs
	return true;

	$handle = fopen(dirname(__FILE__) . "/log/nzrs.log", 'a');
	ob_start();
	echo "\n================= $func =================\n";
	print_r($params);
	$text = ob_get_contents();
	ob_end_clean();
	fwrite($handle, $text);
	fclose($handle);
}

function _nzrs_create_table()
{

	//	Capsule::schema()->table('tbldomains', function (Blueprint $table) {
	//		$table->increments('id')->unsigned()->change();
	//	});

	if (!Capsule::schema()->hasTable('epp_domain_status')) {
		try {
			Capsule::schema()->create('epp_domain_status',
			function (Blueprint $table)
			{
				/** @var \Illuminate\Database\Schema\Blueprint $table */
				$table->increments('id');
				$table->integer('domain_id');

				// $table->integer('domain_id')->unsigned();

				$table->enum('status', array(
					'clientDeleteProhibited',
					'clientHold',
					'clientRenewProhibited',
					'clientTransferProhibited',
					'clientUpdateProhibited',
					'inactive',
					'ok',
					'pendingCreate',
					'pendingDelete',
					'pendingRenew',
					'pendingTransfer',
					'pendingUpdate',
					'serverDeleteProhibited',
					'serverHold',
					'serverRenewProhibited',
					'serverTransferProhibited',
					'serverUpdateProhibited'
				))->default('ok');
				$table->unique(array(
					'domain_id',
					'status'
				));
				$table->foreign('domain_id')->references('id')->on('tbldomains')->onDelete('cascade');
			});
		}

		catch(Exception $e) {
			echo "Unable to create table 'epp_domain_status': {$e->getMessage() }";
		}
	}
}

function _nzrs_create_column()
{
	if (!Capsule::schema()->hasColumn('tbldomains', 'trstatus')) {
		try {
			Capsule::schema()->table('tbldomains',
			function (Blueprint $table)
			{
				$table->enum('trstatus', array(
					'clientApproved',
					'clientCancelled',
					'clientRejected',
					'pending',
					'serverApproved',
					'serverCancelled'
				))->nullable()->after('status');
			});
		}

		catch(Exception $e) {
			echo "Unable to alter table 'tbldomains' add column 'trstatus': {$e->getMessage() }";
		}
	}
}

?>
