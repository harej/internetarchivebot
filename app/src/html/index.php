<?php

/*
	Copyright (c) 2015-2021, Maximilian Doerr, Internet Archive

	This file is part of IABot's Framework.

	IABot is free software: you can redistribute it and/or modify
	it under the terms of the GNU Affero General Public License as published by
	the Free Software Foundation, either version 3 of the License, or
	(at your option) any later version.

	InternetArchiveBot is distributed in the hope that it will be useful,
	but WITHOUT ANY WARRANTY; without even the implied warranty of
	MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
	GNU Affero General Public License for more details.

	You should have received a copy of the GNU Affero General Public License
	along with InternetArchiveBot.  If not, see <https://www.gnu.org/licenses/agpl-3.0.html>.
*/

define( 'UNIQUEID', md5( microtime() ) );
ini_set( 'memory_limit', '256M' );
require_once( 'loader.php' );

//List pages that require full authorization to use
$forceAuthorization = [ 'runbotsingle', 'wikiconfig', 'systemconfig' ];
if( !empty( $_REQUEST['page'] ) && in_array( $_REQUEST['page'], $forceAuthorization ) ) define( 'GUIFULLAUTH', true );

$dbObject = new DB2();
$oauthObject = new OAuth( false, $dbObject );
$userObject = new User( $dbObject, $oauthObject );
$userCache = [];
if( $clearChecksum ) invalidateChecksum();

if( !is_null( $userObject->getDefaultWiki() ) && $userObject->getDefaultWiki() !== WIKIPEDIA &&
    isset( $_GET['returnedfrom'] )
) {
	header( "Location: " . $_SERVER['REQUEST_URI'] . "&wiki=" . $userObject->getDefaultWiki() );
	exit( 0 );
}
define( 'USERLANGUAGE', $userObject->getLanguage() );

use Wikimedia\DeadlinkChecker\CheckIfDead;

$checkIfDead = new CheckIfDead();

//workaround for broken PHPstorm
//Do some POST cleanup to convert everything to a newline.
$_POST = str_ireplace( "%0D%0A", "%0A", file_get_contents( 'php://input' ) );
$_POST = str_ireplace( "%0A", "\n", $_POST );
$_POST = trim( $_POST );
$_POST = str_replace( "\n", "%0A", $_POST );
parse_str( $_POST, $_POST );

if( empty( $_GET ) && empty( $_POST ) ) {
	$oauthObject->storeArguments();
	$loadedArguments = [];
} elseif( isset( $_GET['returnedfrom'] ) ) {
	$oauthObject->recallArguments();
	$loadedArguments = array_replace( $_GET, $_POST );
} else {
	$oauthObject->storeArguments();
	$loadedArguments = array_replace( $_GET, $_POST );
}

if( !defined( 'GUIREDIRECTED' ) ) {
	if( $userObject->defineGroups() === false ) {
		if( $loadedArguments['page'] != "systemconfig" || $loadedArguments['systempage'] != "configuregroups" ) {
			header( "HTTP/1.1 307 Temporary Redirect", true, 307 );
			header( "Location: index.php?page=systemconfig&systempage=configuregroups", true, 307 );
			die( "Groups need to be defined first." );
		}
	}
}

if( isset( $locales[$userObject->getLanguage()] ) ) {
	if( !in_array( setlocale( LC_ALL, $locales[$userObject->getLanguage()]), $locales[$userObject->getLanguage()] ) ) {
		//Uh-oh!! None of the locale definitions are supported on this system.
		echo "<!-- Missing locale for \"{$userObject->getLanguage()}\" -->\n";
		if( !method_exists( "IABotLocalization", "localize_".$userObject->getLanguage() ) ) {
			echo "<!-- No fallback function found, application will use system default -->\n";
		} else {
			echo "<!-- Internal locale profile available in application.  Using that instead -->\n";
		}
		unset( $locales[$userObject->getLanguage()] );
	}
}

if( isset( $loadedArguments['disableoverride'] ) ) {
	if( $loadedArguments['disableoverride'] == "yes" && validatePermission( "overridelockout", false ) ) {
		$_SESSION['overridelockout'] = true;
	} else {
		unset( $_SESSION['overridelockout'] );
	}
}

$languages = DB::getConfiguration( "global", "languages", $userObject->getLanguage() );

if( ( file_exists( "gui.maintenance.json" ) || $disableInterface === true ) &&
    !isset( $_SESSION['overridelockout'] ) ) {
	$mainHTML = new HTMLLoader( "maindisabled", $userObject->getLanguage() );
	$mainHTML->loadWikisi18n();
	$mainHTML->loadLanguages();
	if( isset( $loadedArguments['action'] ) ) {
		switch( $loadedArguments['action'] ) {
			case "loadmaintenancejson":
		}
	}
	if( file_exists( "gui.maintenance.json" ) ) {
		if( isset( $loadedArguments['action'] ) ) {
			switch( $loadedArguments['action'] ) {
				case "loadmaintenancejson":
					$json = json_decode( file_get_contents( "gui.maintenance.json" ), true );
					die( json_encode( $json ) );
			}
		}
		loadMaintenanceProgress();
	} else loadDisabledInterface();
	if( !validatePermission( "overridelockout", false ) ) {
		$mainHTML->disableLockOutOverride();
	}
	goto finishloading;
} else {
	$mainHTML = new HTMLLoader( "main", $userObject->getLanguage() );
	if( file_exists( "gui.maintenance.json" ) ) {
		if( isset( $loadedArguments['action'] ) ) {
			switch( $loadedArguments['action'] ) {
				case "loadmaintenancejson":
					$json = json_decode( file_get_contents( "gui.maintenance.json" ), true );
					die( json_encode( $json ) );
			}
		}
		$mainHTML->setMaintenanceMessage( file_exists( "gui.maintenance.json" ) );
	}
}

$mainHTML->loadWikisi18n();
$mainHTML->loadLanguages();

if( isset( $loadedArguments['action'] ) ) {
	if( $oauthObject->isLoggedOn() === true ) {
		if( $userObject->getLastAction() <= 0 ) {
			if( loadToSPage() === true ) goto quickreload;
			else exit( 0 );
		} else {
			switch( $loadedArguments['action'] ) {
				case "changepermissions":
					if( changeUserPermissions() ) goto quickreload;
					break;
				case "toggleblock":
					if( toggleBlockStatus() ) goto quickreload;
					break;
				case "togglefpstatus":
					if( toggleFPStatus() ) goto quickreload;
					break;
				case "reviewreportedurls":
					if( runCheckIfDead() ) goto quickreload;
					break;
				case "massbqchange":
					if( massChangeBQJobs() ) goto quickreload;
					break;
				case "togglebqstatus":
					if( toggleBQStatus() ) goto quickreload;
					break;
				case "killjob":
					if( toggleBQStatus( true ) ) goto quickreload;
					break;
				case "submitfpreport":
					if( reportFalsePositive() ) goto quickreload;
					break;
				case "changepreferences":
					if( changePreferences() ) goto quickreload;
					break;
				case "submiturldata":
					if( changeURLData() ) goto quickreload;
					break;
				case "submitdomaindata":
					if( changeDomainData() ) goto quickreload;
					break;
				case "analyzepage":
					if( analyzePage() ) goto quickreload;
					break;
				case "submitbotjob":
					if( submitBotJob() ) goto quickreload;
					break;
				case "defineusergroup":
					if( changeUserGroups() ) goto quickreload;
					break;
				case "definearchivetemplate":
					if( changeArchiveRules() ) goto quickreload;
					break;
				case "submitvalues":
					if( changeConfiguration() ) goto quickreload;
					break;
				case "togglerunpage":
					if( toggleRunPage() ) goto quickreload;
					break;
				case "updateciterules":
					if( updateCiteRules() ) goto quickreload;
					break;
				case "importcitoid":
					if( importCiteRules() ) goto quickreload;
					break;
			}
		}
	} else {
		loadLoginNeededPage();
	}
}
quickreload:
if( isset( $loadedArguments['page'] ) ) {
	if( $oauthObject->isLoggedOn() === true ) {
		if( $userObject->getLastAction() <= 0 ) {
			if( loadToSPage() === true ) goto quickreload;
		} else {
			switch( $loadedArguments['page'] ) {
				case "viewjob":
					loadJobViewer();
					break;
				case "runbotsingle":
					loadPageAnalyser();
					break;
				case "runbotqueue":
					loadBotQueuer();
					break;
				case "manageurlsingle":
					loadURLInterface();
					break;
				case "manageurldomain":
					loadDomainInterface();
					break;
				case "reportfalsepositive":
					loadFPReporter();
					break;
				case "reportbug":
					loadBugReporter();
					break;
				case "metalogs":
					loadLogViewer();
					break;
				case "metausers":
					loadUserSearch();
					break;
				case "metainfo":
					loadInterfaceInfo();
					break;
				case "metafpreview":
					loadFPReportMeta();
					break;
				case "metabotqueue":
					loadBotQueue();
					break;
				case "user":
					loadUserPage();
					break;
				case "userpreferences":
					loadUserPreferences();
					break;
				case "performancemetrics":
					loadXHProfData();
					break;
				case "wikiconfig":
					loadConfigWiki( false );
					break;
				case "systemconfig":
					loadSystemPages();
					break;
				case "runpages":
					loadRunPages();
					break;
				default:
					load404Page();
					break;
			}
		}
	} else {
		switch( $loadedArguments['page'] ) {
			case "viewjob":
			case "runbotsingle":
			case "runbotqueue":
			case "manageurlsingle":
			case "manageurldomain":
			case "reportfalsepositive":
			case "metalogs":
			case "metausers":
			case "metafpreview":
			case "metabotqueue":
			case "user":
			case "userpreferences":
			case "performancemetrics":
			case "wikiconfig":
			case "systemconfig":
			case "runpages":
				loadLoginNeededPage();
				break;
			case "reportbug":
				loadBugReporter();
				break;
			case "metainfo":
				loadInterfaceInfo();
				break;
			default:
				load404Page();
				break;
		}
	}
} else {
	loadHomePage();
}

finishloading:
$sql =
	"SELECT COUNT(*) AS count FROM externallinks_user WHERE `last_action` >= '" . date( 'Y-m-d H:i:s', time() - 300 ) .
	"' OR `last_login` >= '" . date( 'Y-m-d H:i:s', time() - 300 ) . "';";
$res = $dbObject->queryDB( $sql );
if( $result = mysqli_fetch_assoc( $res ) ) {
	$mainHTML->assignAfterElement( "activeusers5", $result['count'] );
	mysqli_free_result( $res );
}

$mainHTML->assignElement( "currentwiki", "{{{" . $accessibleWikis[WIKIPEDIA]['i18nsourcename'] . WIKIPEDIA . "name}}}"
);
$tmp = $accessibleWikis;
unset( $tmp[WIKIPEDIA] );
$elementText = "";
foreach( $tmp as $wiki => $info ) {
	$urlbuilder = $loadedArguments;
	unset( $urlbuilder['action'], $urlbuilder['token'], $urlbuilder['checksum'] );
	$urlbuilder['wiki'] = $wiki;
	$elementText .= "<li><a href=\"index.php?" . http_build_query( $urlbuilder ) . "\">" .
	                "{{{" . $tmp[$wiki]['i18nsourcename'] . $wiki . "name}}}" .
	                "</a></li>\n";
}
$mainHTML->assignElement( "wikimenu", $elementText );
$mainHTML->assignElement( "currentlang", $languages[$userObject->getLanguage()] );
$tmp = $languages;
unset( $tmp[$userObject->getLanguage()] );
$elementText = "";
foreach( $tmp as $langCode => $langName ) {
	$urlbuilder = $loadedArguments;
	unset( $urlbuilder['action'], $urlbuilder['token'], $urlbuilder['checksum'] );
	$urlbuilder['lang'] = $langCode;
	$elementText .= "<li><a href=\"index.php?" . http_build_query( $urlbuilder ) . "\">" .
	                $langName . "</a></li>\n";
}
$mainHTML->assignElement( "langmenu", $elementText );
$mainHTML->setUserMenuElement( $userObject->getLanguage(), $oauthObject->getUsername(), $oauthObject->getUserID() );
if( !is_null( $userObject->getTheme() ) ) $mainHTML->assignElement( "csstheme", $userObject->getTheme() );
else $mainHTML->assignElement( "csstheme", "lumen" );
$mainHTML->assignAfterElement( "defaulttheme", "lumen" );
$mainHTML->assignAfterElement( "csrftoken", $oauthObject->getCSRFToken() );
$mainHTML->assignAfterElement( "checksum", $oauthObject->getChecksumToken() );
if( $userObject->getAnalyticsPermission() ) $mainHTML->assignElement( "analyticshtml", "<script src=\"static/analytics.js\"></script>" );
if( !$userObject->useMultipleTabs() ) $mainHTML->assignElement( "tabshtml", "<script src=\"static/restrict-tabs.js\"></script>" );
else $mainHTML->assignElement( "tabshtml", "<script src=\"static/unrestrict-tabs.js\"></script>" );
if( $userObject->debugEnabled() ) $mainHTML->loadDebugWarning( $userObject->getLanguage() );
$mainHTML->finalize();
echo $mainHTML->getLoadedTemplate();