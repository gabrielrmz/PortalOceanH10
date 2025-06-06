<?php
// Copyright 1999-2024. WebPros International GmbH. All rights reserved.

define('APP_PATH', dirname(__FILE__));
define('DEBUG', 0); // allow to dump sql logs to output
define('PRE_UPGRADE_SCRIPT_VERSION', '18.0.62.0'); //script version
define('PLESK_VERSION', '18.0.62'); // latest Plesk version
@date_default_timezone_set(@date_default_timezone_get());

if (!defined('PHP_EOL')) {
    define('PHP_EOL', "\n", true);
}

define('LOG_PATH', APP_PATH . '/plesk_preupgrade_checker.log');
define('LOG_JSON', 'plesk_preupgrade_checker.json');

$phpType = php_sapi_name();
if (substr($phpType, 0, 3) == 'cgi') {
    //:INFO: set max execution time 1hr
    @set_time_limit(3600);
}

class Plesk17KnownIssues
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            //:INFO: Plesk installation is not found. You will have no problems with upgrade, go on and install Plesk 10
            return;
        }

        if (Util::isLinux()) {

            $this->_checkMainIP(); //:INFO: Checking for main IP address https://support.plesk.com/hc/articles/12377857361687
            $this->_checkMySQLDatabaseUserRoot(); //:INFO: Plesk user "root" for MySQL database servers have not access to phpMyAdmin https://support.plesk.com/hc/articles/12378148229399
            $this->_checkProftpdIPv6(); //:INFO: #94489 FTP service proftpd cannot be started by xinetd if IPv6 is disabled https://support.plesk.com/hc/articles/12377796102807
            $this->_checkSwCollectdIntervalSetting(); //:INFO: #105405 https://support.plesk.com/hc/articles/213362569
            $this->_checkApacheStatus();
            $this->_checkImmutableBitOnPleskFiles(); //:INFO: #128414 https://support.plesk.com/hc/articles/12377589682327
            $this->_checkAbilityToChmodInDumpd(); //:INFO: ERROR while trying to backup MySQL database. https://support.plesk.com/hc/en-us/articles/12377010033943
            $this->_checkIpcollectionReference(); //:INFO: #72751 https://support.plesk.com/hc/articles/12377666752279
            $this->_checkApsApplicationContext(); //:INFO: Broken contexts of the APS applications can lead to errors at building Apache web server configuration  https://support.plesk.com/hc/articles/12377671820311
            $this->_checkApsTablesInnoDB();
            $this->_checkCustomWebServerConfigTemplates(); //:INFO: #PPPM-1195 https://support.plesk.com/hc/articles/12377740416151
            $this->_checkMixedCaseDomainIssues(); //:INFO: #PPPM-4284 https://support.plesk.com/hc/en-us/articles/12377171904151

            if (PleskOS::isDebLike()) {
                $this->_checkSymLinkToOptPsa(); //:INFO: Check that symbolic link /usr/local/psa actually exists on Debian-like OSes https://support.plesk.com/hc/articles/12377511731991
            }

            if (Util::isVz()) {
                $this->_checkShmPagesLimit(); //:INFO: PSA service does not start. Unable to allocate shared memory segment. https://support.plesk.com/hc/articles/12377744827927

                if (PleskOS::isRedHatLike()) {
                    $this->_checkMailDriversConflict(); //:INFO: #PPPM-955 https://support.plesk.com/hc/articles/12378148767895
                }
            }
        }

        $this->_checkForCryptPasswords();
        $this->_checkMysqlServersTable(); //:INFO: Checking existing table mysql.servers
        $this->_checkPleskTCPPorts(); //:INFO: Check the availability of Plesk TCP ports https://support.plesk.com/hc/articles/12377821243159

        if (Util::isWindows()) {
            $this->_checkPhprcSystemVariable(); //:INFO: #PPPM-294  Checking for PHPRC system variable
            $this->_unknownISAPIfilters(); //:INFO: Checking for unknown ISAPI filters and show warning https://support.plesk.com/hc/articles/213913765
            $this->_checkMSVCR(); //:INFO: Just warning about possible issues related to Microsoft Visual C++ Redistributable Packages https://support.plesk.com/hc/articles/115000201014
            $this->_checkIisFcgiDllVersion(); //:INFO: Check iisfcgi.dll file version https://support.plesk.com/hc/articles/12378148258199
            $this->_checkCDONTSmailrootFolder(); //:INFO: After upgrade Plesk change permissions on folder of Collaboration Data Objects (CDO) for NTS (CDONTS) to default, https://support.plesk.com/hc/articles/12377887661335
            $this->_checkNullClientLogin(); //:INFO: #118963 https://support.plesk.com/hc/articles/12378122202391
            $this->checkDomainControllerLocation(); //:INFO: https://support.plesk.com/hc/articles/12377107094167
        }
    }

    //:INFO: PSA service does not start. Unable to allocate shared memory segment. https://support.plesk.com/hc/articles/12377744827927
    function _checkShmPagesLimit()
    {
        $log = Log::getInstance("Checking for limit shmpages", true);
        $ubc = Util::getUserBeanCounters();
        if ((int)$ubc['shmpages']['limit'] < 40960) {
            $log->emergency("Virtuozzo Container set the \"shmpages\" limit to {$ubc['shmpages']['limit']}, which is too low. This may cause the sw-engine service not to start. To resolve this issue, refer to the article at https://support.plesk.com/hc/articles/12377744827927");
            $log->resultWarning();
            return;
        }

        $log->resultOk();
    }

    //:INFO: #PPPM-294
    function _checkPhprcSystemVariable()
    {
        $log = Log::getInstance("Checking for PHPRC system variable", true);

        $phprc = getenv('PHPRC');

        if ($phprc) {
            $log->emergency('The environment variable PHPRC is present in the system. This variable may lead to upgrade failure. Please delete this variable from the system environment.');
            $log->resultWarning();
            return;
        }

        $log->resultOk();
    }

    //:INFO: ERROR while trying to backup MySQL database. https://support.plesk.com/hc/en-us/articles/12377010033943
    function _checkAbilityToChmodInDumpd()
    {
        $log = Log::getInstance("Checking the possibility to change the permissions of files in the DUMP_D directory", true);

        $dump_d = Util::getSettingFromPsaConf('DUMP_D');
        if (is_null($dump_d)) {
            $log->warning('Unable to obtain the path to the directory defined by the DUMP_D parameter. Check that the DUMP_D parameter is set in the /etc/psa/psa.conf file.');
            $log->resultWarning();
            return;
        }

        $file = $dump_d . '/pre_upgrade_test_checkAbilityToChmodInDumpd';

        if (false === file_put_contents($file, 'test')) {
            $log->emergency('Unable to write in the ' . $dump_d . ' directory. The upgrade procedure will fail. Check that the folder exists and you have write permissions for it, and repeat upgrading. ');
            $log->resultWarning();
            return;
        } else {
            $result = @chmod($file, 0600);
            unlink($file);
            if (!$result) {
                $log->emergency(
                    'Unable to change the permissions of files in the ' . $dump_d . ' directory. '
                    . 'The upgrade procedure will fail. Please refer to https://support.plesk.com/hc/articles/12377010033943 for details.'
                );
                $log->resultError();
                return;
            }
        }
        $log->resultOk();
    }

    //:INFO: #128414 https://support.plesk.com/hc/articles/12377589682327
    function _checkImmutableBitOnPleskFiles()
    {
        $log = Log::getInstance("Checking Panel files for the immutable bit attribute");

        $cmd = 'lsattr -R /usr/local/psa/ 2>/dev/null |awk \'{split($1, a, ""); if (a[5] == "i") {print;}}\'';
        $output = Util::exec($cmd, $code);
        $files = explode('\n', $output);

        if (!empty($output)) {
            $log->info('The immutable bit attribute of the following Panel files can interrupt the upgrade procedure:');
            foreach ($files as $file) {
                $log->info($file);
            }
            $log->emergency('Files with the immutable bit attribute were found. Please check https://support.plesk.com/hc/articles/12377589682327 for details.');
            $log->resultWarning();
            return;
        }

        $log->resultOk();
    }

    //:INFO: #PPPM-1195 https://support.plesk.com/hc/articles/12377740416151
    function _checkCustomWebServerConfigTemplates()
    {
        $log = Log::getInstance("Checking for custom web server configuration templates");
        $pleskDir = Util::getSettingFromPsaConf('PRODUCT_ROOT_D');
        $customTemplatesPath = $pleskDir . '/admin/conf/templates/custom';

        if (is_dir($customTemplatesPath)) {
            $log->warning("Directory {$customTemplatesPath} for custom web server configuration templates was found. Custom templates might be incompatible with a new Plesk version, and this might lead to failure to generate web server configuration files. Remove the directory to get rid of this warning. "
                    . "Please check https://support.plesk.com/hc/articles/12377740416151 for details.");
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    //:INFO: #PPPM-955 https://support.plesk.com/hc/articles/12378148767895
    function _checkMailDriversConflict()
    {
        $log = Log::getInstance("Checking for a Plesk mail drivers conflict");

        if (((true === PackageManager::isInstalled('psa-mail-pc-driver') || true === PackageManager::isInstalled('plesk-mail-pc-driver'))
                && true === PackageManager::isInstalled('psa-qmail'))
            || ((true === PackageManager::isInstalled('psa-mail-pc-driver') || true === PackageManager::isInstalled('plesk-mail-pc-driver'))
                && true === PackageManager::isInstalled('psa-qmail-rblsmtpd'))) {
            $log->warning("Plesk upgrade by EZ templates failed if psa-mail-pc-driver and psa-qmail or psa-qmail-rblsmtpd packages are installed. "
                . "Please check https://support.plesk.com/hc/articles/12378148767895 for details.");
            $log->resultWarning();
            return;
        }

        $log->resultOk();
    }

    //:INFO: #118963 https://support.plesk.com/hc/articles/12378122202391
    function _checkNullClientLogin()
    {
        $log = Log::getInstance("Checking for accounts with empty user names");

        $mysql = PleskDb::getInstance();
        $sql = "SELECT domains.id, domains.name, clients.login FROM domains LEFT JOIN clients ON clients.id=domains.cl_id WHERE clients.login is NULL";
        $nullLogins = $mysql->fetchAll($sql);

        if (!empty($nullLogins)) {
            $log->warning('There are accounts with empty user names. This problem can cause the backup or migration operation to fail. Please see https://support.plesk.com/hc/articles/12378122202391 for the solution.');
            $log->resultWarning();
            return;
        }

        $log->resultOk();
    }

    //:INFO: #105405 https://support.plesk.com/hc/articles/213362569
    function _checkSwCollectdIntervalSetting()
    {
        $log = Log::getInstance("Checking the 'Interval' parameter in the sw-collectd configuration file");

        $collectd_config = '/etc/sw-collectd/collectd.conf';
        if (file_exists($collectd_config)) {
            if (!is_file($collectd_config) || !is_readable($collectd_config))
            return;

            $config_content = Util::readfileToArray($collectd_config);
            if ($config_content) {
                foreach ($config_content as $line) {
                    if (preg_match('/Interval\s*\d+$/', $line, $match)) {
                        if (preg_match('/Interval\s*10$/', $line, $match)) {
                            $log->warning('If you leave the default value of the "Interval" parameter in the ' . $collectd_config . ', sw-collectd may heavily load the system. Please see https://support.plesk.com/hc/articles/213362569 for details.');
                            $log->resultWarning();
                            return;
                        }
                        $log->resultOk();
                        return;
                    }
                }
                $log->warning('If you leave the default value of the "Interval" parameter in the ' . $collectd_config . ', sw-collectd may heavily load the system. Please see https://support.plesk.com/hc/articles/213362569 for details.');
                $log->resultWarning();
                return;
            }
        }
    }

    private function _checkApacheStatus()
    {
        $log = Log::getInstance("Checking Apache status");

        $apacheCtl = file_exists('/usr/sbin/apache2ctl') ? '/usr/sbin/apache2ctl' : '/usr/sbin/apachectl';

        if (!is_executable($apacheCtl)) {
            return;
        }

        $resultCode = 0;
        Util::Exec("$apacheCtl -t 2>/dev/null", $resultCode);

        if (0 !== $resultCode) {
            $log->error("The Apache configuration is broken. Run '$apacheCtl -t' to see the detailed info.");
            $log->resultError();
            return;
        }

        $log->resultOk();
    }

    //:INFO: #72751  https://support.plesk.com/hc/articles/12377666752279
    function _checkIpcollectionReference()
    {
        $log = Log::getInstance("Checking consistency of the IP addresses list in the Panel database");

        $mysql = PleskDb::getInstance();
        $sql = "SELECT 1 FROM ip_pool, clients, IpAddressesCollections, domains, DomainServices, IP_Addresses WHERE DomainServices.ipCollectionId = IpAddressesCollections.ipCollectionId AND domains.id=DomainServices.dom_id AND clients.id=domains.cl_id AND ipAddressId NOT IN (select id from IP_Addresses) AND IP_Addresses.id = ip_pool.ip_address_id AND pool_id = ip_pool.id GROUP BY pool_id";
        $brokenIps = $mysql->fetchAll($sql);
        $sql = "select 1 from DomainServices, domains, clients, ip_pool where ipCollectionId not in (select IpAddressesCollections.ipCollectionId from IpAddressesCollections) and domains.id=DomainServices.dom_id and clients.id = domains.cl_id and ip_pool.id = clients.pool_id and DomainServices.type='web' group by ipCollectionId";
        $brokenCollections = $mysql->fetchAll($sql);

        if (!empty($brokenIps) || !empty($brokenCollections)) {
            $log->warning('Some database entries related to Panel IP addresses are corrupted. Please see https://support.plesk.com/hc/articles/12377666752279 for the solution.');
            $log->resultWarning();
            return;
        }

        $log->resultOk();
    }

    //:INFO: Broken contexts of the APS applications can lead to errors at building Apache web server configuration https://support.plesk.com/hc/articles/12377671820311
    function _checkApsApplicationContext()
    {
        $log = Log::getInstance("Checking installed APS applications");
        $mysql = PleskDb::getInstance();
        $sql = "SELECT * FROM apsContexts WHERE (pleskType = 'hosting' OR pleskType = 'subdomain') AND subscriptionId = 0";
        $brokenContexts = $mysql->fetchAll($sql);

        if (!empty($brokenContexts)) {
            $log->warning('Some database entries related to the installed APS applications are corrupted. Please see https://support.plesk.com/hc/articles/12377671820311 for the solution.');
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    //:INFO: #94489 FTP service proftpd cannot be started by xinetd if IPv6 is disabled https://support.plesk.com/hc/articles/12377796102807
    function _checkProftpdIPv6()
    {
        $log = Log::getInstance("Checking proftpd settings");

        $inet6 = '/proc/net/if_inet6';
        if (!file_exists($inet6) || !@file_get_contents($inet6)) {
            $proftpd_config = '/etc/xinetd.d/ftp_psa';
            if (!is_file($proftpd_config) || !is_readable($proftpd_config))
                return null;

            $config_content = Util::readfileToArray($proftpd_config);
            if ($config_content) {
                for ($i=0; $i<=count($config_content)-1; $i++) {
                    if (preg_match('/flags.+IPv6$/', $config_content[$i], $match)) {
                        $log->warning('The proftpd FTP service will fail to start in case the support for IPv6 is disabled on the server. Please check https://support.plesk.com/hc/articles/12377796102807 for details.');
                        $log->resultWarning();
                        return;
                    }
                }
            }
        }
        $log->resultOk();
    }

    //:INFO: Check the availability of Plesk Panel TCP ports
    function _checkPleskTCPPorts()
    {
        $log = Log::getInstance('Checking the availability of Plesk Panel TCP ports');

        $plesk_ports = array('8880' => 'Plesk Panel non-secure HTTP port', '8443' => 'Plesk Panel secure HTTPS port');

        $mysql = PleskDb::getInstance();
        $sql = "select ip_address from IP_Addresses";
        $ip_addresses = $mysql->fetchAll($sql);
        $warning = false;
        if (count($ip_addresses)>0) {
            if (Util::isLinux()) {
                $ipv4 = Util::getIPv4ListOnLinux();
                $ipv6 = Util::getIPv6ListOnLinux();
                if ($ipv6) {
                    $ipsInSystem = array_merge($ipv4, $ipv6);
                } else {
                    $ipsInSystem = $ipv4;
                }
            } else {
                $ipsInSystem = Util::getIPListOnWindows();
            }
            foreach ($ip_addresses as $ip) {
                foreach ($plesk_ports as $port => $description) {
                    if (PleskValidator::validateIPv4($ip['ip_address']) && in_array($ip['ip_address'], $ipsInSystem)) {
                        $fp = @fsockopen($ip['ip_address'], $port, $errno, $errstr, 1);
                    } elseif (PleskValidator::validateIPv6($ip['ip_address']) && in_array($ip['ip_address'], $ipsInSystem)) {
                        $fp = @fsockopen('[' . $ip['ip_address'] . ']', $port, $errno, $errstr, 1);
                    } else {
                        $log->warning('IP address registered in Plesk is invalid or broken: ' . $ip['ip_address']);
                        $log->resultWarning();
                        return;
                    }
                    if (!$fp) {
                        // $errno 110 means "timed out", 111 means "refused"
                        $log->info('Unable to connect to IP address ' . $ip['ip_address'] . ' on ' . $description . ' ' . $port . ': ' . $errstr);
                        $warning = true;
                    }
                }
            }
        }
        if ($warning) {
            $log->warning('Unable to connect to some Plesk ports. Please see ' . LOG_PATH . ' for details. Find the full list of the required open ports at https://support.plesk.com/hc/articles/12377821243159 ');
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    //:INFO: Plesk user "root" for MySQL database servers have not access to phpMyAdmin https://support.plesk.com/hc/articles/12378148229399
    function _checkMySQLDatabaseUserRoot()
    {
        $log = Log::getInstance('Checking existence of Plesk user "root" for MariaDB/MySQL database servers');

        $psaroot = Util::getSettingFromPsaConf('PRODUCT_ROOT_D');

        if (PleskVersion::is_below_17_9()) {
            $phpMyAdminConfFile = $psaroot . '/admin/htdocs/domains/databases/phpMyAdmin/libraries/config.default.php';
        } else {
            $phpMyAdminConfFile = $psaroot . '/phpMyAdmin/libraries/config.default.php';
        }

        if (file_exists($phpMyAdminConfFile)) {
            $phpMyAdminConfFileContent = file_get_contents($phpMyAdminConfFile);
            if (!preg_match("/\[\'AllowRoot\'\]\s*=\s*true\s*\;/", $phpMyAdminConfFileContent)) {
                $mysql = PleskDb::getInstance();
                $sql = "select login, data_bases.name as db_name, displayName as domain_name from db_users, data_bases, domains where db_users.db_id = data_bases.id and data_bases.dom_id = domains.id and data_bases.type = 'mysql' and login = 'root'";
                $dbusers = $mysql->fetchAll($sql);

                foreach ($dbusers as $user) {
                    $log->warning('The database user "' . $user['login'] . '"  (database "' . $user['db_name'] . '" at "' . $user['domain_name'] . '") has no access to phpMyAdmin. Please check https://support.plesk.com/hc/articles/12378148229399 for more details.');
                    $log->resultWarning();
                    return;
                }
            }
        }

        $log->resultOk();
    }

    //:INFO: After upgrade Plesk change permissions on folder of Collaboration Data Objects (CDO) for NTS (CDONTS) to default, https://support.plesk.com/hc/articles/12377887661335
    function _checkCDONTSmailrootFolder()
    {
        $log = Log::getInstance('Checking for CDONTS mailroot folder');

        $mailroot = Util::getSystemDisk() . 'inetpub\mailroot\pickup';

        if (is_dir($mailroot)) {
            $log->warning('After upgrade you have to add write permissions to psacln group on folder ' . $mailroot . '. Please, check https://support.plesk.com/hc/articles/12377887661335 for more details.');
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    //:INFO: Check iisfcgi.dll file version https://support.plesk.com/hc/articles/12378148258199
    function _checkIisFcgiDllVersion()
    {
        $log = Log::getInstance("Checking the iisfcgi.dll file version");

        $windir = Util::getSystemRoot();
        $iisfcgi = $windir . '\system32\inetsrv\iisfcgi.dll';
        if (file_exists($iisfcgi)) {
            $version = Util::getFileVersion($iisfcgi);
            if (version_compare($version, '7.5.0', '>')
                && version_compare($version, '7.5.7600.16632', '<')) {
                $log->warning('File iisfcgi.dll version ' . $version . ' is outdated. Please, check article https://support.plesk.com/hc/articles/12378148258199 for details');
                return;
            }
        }
        $log->resultOk();
    }

    //:INFO: Checking for main IP address https://support.plesk.com/hc/articles/12377857361687
    function _checkMainIP()
    {
        $log = Log::getInstance("Checking for main IP address");

        $mysql = PleskDb::getInstance();
        $sql = 'select * from IP_Addresses';
        $ips = $mysql->fetchAll($sql);
        $mainexists = false;
        foreach ($ips as $ip) {
            if (isset($ip['main'])) {
                if ($ip['main'] == 'true') {
                    $mainexists = true;
                }
            } else {
                $log->info('No field "main" in table IP_Addresses.');
                $log->resultOk();
                return;
            }
        }

        if (!$mainexists) {
            $warn = 'Unable to find "main" IP address in psa database. Please, check https://support.plesk.com/hc/articles/12377857361687 for more details.';
            $log->warning($warn);
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    //:INFO: Checking existing table mysql.servers https://support.plesk.com/hc/articles/12377850098455
    function _checkMysqlServersTable()
    {
        $log = Log::getInstance('Checking table "servers" in database "mysql"');

        $mySQLServerVersion = Util::getMySQLServerVersion();
        if (version_compare($mySQLServerVersion, '5.1.0', '>=')) {
            $credentials = Util::getDefaultClientMySQLServerCredentials();

            if (preg_match('/AES-128-CBC/', $credentials['admin_password'])) {
                $log->info('The administrator\'s password for the default MariaDB/MySQL server is encrypted.');
                return;
            }

            $mysql = new DbClientMysql($credentials['host'], $credentials['admin_login'], $credentials['admin_password'] , 'information_schema', $credentials['port']);
            if (!$mysql->hasErrors()) {
                $sql = 'SELECT * FROM information_schema.TABLES  WHERE TABLE_SCHEMA="mysql" and TABLE_NAME="servers"';
                $servers = $mysql->fetchAll($sql);
                if (empty($servers)) {
                    $warn = 'The table "servers" in the database "mysql" does not exist. Please check  https://support.plesk.com/hc/articles/12377850098455 for details.';
                    $log->warning($warn);
                    $log->resultWarning();
                    return;
                }
            }
        }
        $log->resultOk();
    }

    //:INFO: Check that there is symbolic link /usr/local/psa on /opt/psa on Debian-like Oses https://support.plesk.com/hc/articles/12377511731991
    function _checkSymLinkToOptPsa()
    {
        $log = Log::getInstance('Checking symbolic link /usr/local/psa on /opt/psa');

        $link = @realpath('/usr/local/psa/version');
        if (!preg_match('/\/opt\/psa\/version/', $link, $macthes)) {
            $warn = "The symbolic link /usr/local/psa does not exist or has wrong destination. Read article https://support.plesk.com/hc/articles/12377511731991 to fix the issue.";
            $log->warning($warn);
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    //:INFO: Checking for unknown ISAPI filters and show warning https://support.plesk.com/hc/articles/213913765
    function _unknownISAPIfilters()
    {
        $log = Log::getInstance('Detecting installed ISAPI filters');

        if (Util::isUnknownISAPIfilters()) {
            $warn = 'Please read carefully article https://support.plesk.com/hc/articles/213913765, for avoiding possible problems caused by unknown ISAPI filters.';
            $log->warning($warn);
            $log->resultWarning();

            return;
        }
        $log->resultOk();
    }

    //:INFO: Warning about possible issues related to Microsoft Visual C++ Redistributable Packages ?https://support.plesk.com/hc/articles/115000201014
    function _checkMSVCR()
    {
        $log = Log::getInstance('Microsoft Visual C++ Redistributable Packages');

        $warn = 'Please read carefully article https://support.plesk.com/hc/articles/115000201014, for avoiding possible problems caused by Microsoft Visual C++ Redistributable Packages.';
        $log->info($warn);

        return;
    }

    function _checkForCryptPasswords()
    {
        //:INFO: Prevent potential problem with E: Couldn't configure pre-depend plesk-core for psa-firewall, probably a dependency cycle.
        $log = Log::getInstance('Detecting if encrypted passwords are used');

        $db = PleskDb::getInstance();
        $sql = "SELECT COUNT(*) AS cnt FROM accounts WHERE type='crypt' AND password not like '$%';";
        $r = $db->fetchAll($sql);

        if ($r[0]['cnt'] != '0')
        {
            $warn = 'There are ' . $r[0]['cnt'] . ' accounts with passwords encrypted using a deprecated algorithm. Please refer to https://support.plesk.com/hc/articles/12377596588311 for the instructions about how to change the password type to plain.';

            $log->warning($warn);
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    function _checkApsTablesInnoDB()
    {
        $log = Log::getInstance('Checking if apsc database tables have InnoDB engine');

        $db = PleskDb::getInstance();
        $apsDatabase = $db->fetchOne("select val from misc where param = 'aps_database'");
        $sql = "SELECT TABLE_NAME FROM information_schema.TABLES where TABLE_SCHEMA = '$apsDatabase' and ENGINE = 'MyISAM'";
        $myISAMTables = $db->fetchAll($sql);
        if (!empty($myISAMTables)) {
            $myISAMTablesList = implode(', ', array_map('reset', $myISAMTables));
            $warn = 'The are tables in apsc database with MyISAM engine: ' . $myISAMTablesList . '. It would be updated to InnoDB engine.';
            $log->warning($warn);
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    function _checkMixedCaseDomainIssues()
    {
        $log = Log::getInstance("Checking for domains with mixed case names", true);
        $db = PleskDb::getInstance();


        $domains = $db->fetchAll("select id, name, displayName from domains");
        $problemDomains = array();
        foreach ($domains as $domain) {
            if (strtolower($domain['name']) == $domain['name']) {
                continue;
            }
            $problemDomains[] = $domain;
        }
        if (count($problemDomains)) {
            $msg = "Found one or more domains with mixed case names. Such domains may have trouble working with the \"FPM application server by Apache\" handler.\n" .
                implode("\n", array_map(function($row) {
                    return "{$row['id']}\t{$row['displayName']}\t{$row['name']}";
                }, $problemDomains)) . "\n" .
                "A manual fix can be applied to resolve the issue. Read https://support.plesk.com/hc/en-us/articles/12377171904151 for details.";
            $log->warning($msg);
            $log->resultWarning();
            return;
        }
        $log->resultOk();
    }

    private function checkDomainControllerLocation()
    {
        $log = Log::getInstance("Checking for Active Directory Domain Controller and Plesk on the same server", true);
        $cmd = '"' . rtrim(Util::getPleskRootPath(), '\\') . '\admin\bin\serverconf.exe" --list';
        $output = Util::exec($cmd, $code);
        if (preg_match("/IS_DOMAIN_CONTROLLER:\s*true/", $output)) {
            $log->warning('Active Directory Domain Controller and Plesk are on the same server. Read https://support.plesk.com/hc/articles/12377107094167 for details.');
            $log->resultWarning();
        } else {
            $log->resultOk();
        }
    }
}

class Plesk175Requirements
{
    public function validate()
    {
        if (PleskInstallation::isInstalled() && PleskVersion::is_below_17_5() && Util::isLinux()) {
            //:INFO: Check that DUMP_TMP_D is not inside of (or equal to) DUMP_D
            $this->_checkDumpTmpD();
        }
    }

    public function _checkDumpTmpD()
    {
        $log = Log::getInstance('Checking the DUMP_TMP_D directory');

        $dumpD = Util::getSettingFromPsaConf('DUMP_D');
        if (is_null($dumpD)) {
            $log->warning('Unable to obtain the path to the directory defined by the DUMP_D parameter. Check that the DUMP_D parameter is set in the /etc/psa/psa.conf file.');
            $log->resultWarning();
            return;
        }
        $dumpTmpD = Util::getSettingFromPsaConf('DUMP_TMP_D');
        if (is_null($dumpTmpD)) {
            $log->warning('Unable to obtain the path to the directory defined by the DUMP_TMP_D parameter. Check that the DUMP_TMP_D parameter is set in the /etc/psa/psa.conf file.');
            $log->resultWarning();
            return;
        }

        if (strpos(rtrim($dumpTmpD, '/') . '/', rtrim($dumpD, '/') . '/') === 0) {
            $log->error(sprintf('The directory DUMP_TMP_D = %s should not be inside of (or equal to) the directory DUMP_D = %s. Fix these parameters in the /etc/psa/psa.conf file.', $dumpTmpD, $dumpD));
            $log->resultError();
        }

        $log->resultOk();
    }
}

class Plesk178Requirements
{
    public function validate()
    {
        if (PleskInstallation::isInstalled() && Util::isWindows() && PleskVersion::is_below_17_9()) {
            $this->_checkPleskVhostsDir();
        }

        if (PleskVersion::is_below_17_8()) {
            $this->checkTomcat();
            $this->checkMultiServer();
        }
    }

    private function checkTomcat()
    {
        if (Util::isWindows()) {
            $tomcatRegBase = '\\PLESK\\PSA Config\\Config\\Packages\\tomcat\\tomcat';
            /* Supported versions on windows are tomcat5 and tomcat7 */
            $key = '/v InstallDir';
            $isInstalled = Util::regQuery($tomcatRegBase . '7', $key, true) || Util::regQuery($tomcatRegBase . '5', $key, true);
        } else {
            $isInstalled = PackageManager::isInstalled('psa-tomcat-configurator');
        }

        if ($isInstalled
            || (PleskDb::getInstance()->fetchOne('show tables like \'WebApps\'')
                && PleskDb::getInstance()->fetchOne('select count(*) from WebApps'))
        ) {
            $log = Log::getInstance('Checking Apache Tomcat installation');
            $message = <<<EOL
After upgrade Tomcat component will not be available for installing and configuring. Java Tomcat applications will be available via 9080 port only and will not be available via http/https.
You can also completely remove Tomcat component. Please check https://support.plesk.com/hc/en-us/articles/115004715774 for more details.
EOL;
            $log->warning($message);
        }
    }

    private function checkMultiServer()
    {
        if (!PleskModule::isMultiServer()) {
            return;
        }

        $log = Log::getInstance('Checking Plesk Multi Server installation');
        $message = <<<EOL
Upgrade to Plesk Onyx 17.8 and higher is not supported on servers with Plesk Multi Server installed.
Please refer to https://support.plesk.com/hc/en-us/articles/12377284220823 for details.
EOL;
        $log->emergency($message);
        $log->resultError();
    }

    private function _checkPleskVhostsDir()
    {
        $log = Log::getInstance('Checking mount volume for HTTPD_VHOSTS_D directory');

        $vhostsDir = rtrim(Util::regPleskQuery('HTTPD_VHOSTS_D'), "\\");
        Util::exec("mountvol \"{$vhostsDir}\" /L", $code);
        if ($code == 0) {
            $msg = "A disk volume is mounted to the {$vhostsDir} directory." .
                " It will be unmounted during the Plesk upgrade." .
                " As a result, all hosted websites will become unavailable." .
                " Make sure to remount the volume to the {$vhostsDir} directory after the upgrade.";
            $log->emergency($msg);
            $log->resultError();
            return;
        }

        $log->resultOk();
    }
}

class Plesk18Requirements
{
    public function validate()
    {
        if (PleskInstallation::isInstalled()) {
            if (Util::isLinux() && PleskOS::isRedHatLike()) {
                $this->_checkYumDuplicates();
            }
            $this->checkPdUsersLoginCollation();
            $this->checkDomainsGuidCollation();
            $this->checkClientsGuidCollation();
            $this->checkModSecurityModules();
            $this->checkIsFirewallPackageConfigured();
        }
    }

    private function checkModSecurityModules()
    {
        /* Issue actual for Plesk for Windows below 18.0.32 (ModSecurity 2.9.3 and below) */
        if (!Util::isWindows() || PleskVersion::is_18_0_32_or_above()) {
            return;
        }

        $log = Log::getInstance('Checking the status of ModSecurity IIS modules');
        $modules = Util::exec(Util::getSystemRoot() . '\system32\inetsrv\AppCmd.exe list module', $code);
        if ($code) {
            $log->warning('Unable to get the list of IIS modules.');
        } else {
            if (strpos($modules, 'ModSecurity IIS (32bits)') === false && strpos($modules, 'ModSecurity IIS (64bits)') === false) {
                $log->error('Either 32-bit or 64-bit ModSecurity IIS module is absent.');
                $log->resultError();
            }
        }
    }

    // INFO: PPP-46440 checking package duplicates https://support.plesk.com/hc/articles/12377586286615
    private function _checkYumDuplicates()
    {
        $log = Log::getInstance('Checking for RPM packages duplicates');
        if (!file_exists("/usr/bin/package-cleanup"))
        {
            $log->info("package-cleanup is not found. Check for duplicates was skipped");
            return;
        }

        $output = Util::exec("/usr/bin/package-cleanup --cacheonly -q --dupes", $code);
        if ($code != 0)
        {
            // some repos may have no cache at this point or may be broken at all
            // retry with cache recreation and skipping broken repos
            $output = Util::exec("/usr/bin/package-cleanup -q --dupes --setopt='*.skip_if_unavailable=1'", $code);
        }
        if ($code != 0)
        {
            $message = "Unable to detect package duplicates: /usr/bin/package-cleanup --dupes returns $code." .
                        "Output is:\n$output";
            $log->warning($message);
            $log->resultWarning();
            return;
        }

        if (empty($output)) {
            return;
        }

        $message = "Your package system contains duplicated packages, which can lead to broken Plesk update:\n\n" .
                                "$output\n\n" .
                                "Please check https://support.plesk.com/hc/articles/12377586286615 for more details.";

            $log->error($message);
            $log->resultError();
    }

    private function checkPdUsersLoginCollation()
    {
        $log = Log::getInstance('Checking for Protected Directory Users with duplicates in login field.');
        $duplicates = PleskDb::getInstance()->fetchAll(
            'SELECT pd_id, LOWER(login) AS login_ci, COUNT(*) AS duplicates FROM pd_users' .
            ' GROUP BY pd_id, login_ci' .
            ' HAVING duplicates > 1'
        );
        if (!empty($duplicates)) {
            $log->error(
                "Duplicate logins of Protected Directory Users were found in the database:\n\n" .
                implode("\n", array_column($duplicates, 'login_ci')) . "\n\n" .
                "Please check https://support.plesk.com/hc/en-us/articles/360014743900 for more details."
            );
            $log->resultError();
        }
    }

    private function checkDomainsGuidCollation()
    {
        $log = Log::getInstance('Checking "domains" table with duplicates in guid field.');
        $duplicates = PleskDb::getInstance()->fetchAll(
            'SELECT guid, COUNT(*) AS duplicates FROM domains'
            . ' GROUP BY guid'
            . ' HAVING duplicates > 1'
        );

        if (!empty($duplicates)) {
            $log->error(
                "Duplicate guid were found in the 'domains' table:\n\n" .
                implode("\n", array_column($duplicates, 'guid')) . "\n\n"
                . "Please check https://support.plesk.com/hc/en-us/articles/12377018323351 for more details."
            );
            $log->resultError();
        }
    }

    private function checkClientsGuidCollation()
    {
        $log = Log::getInstance('Checking "clients" table with duplicates in guid field.');
        $duplicates = PleskDb::getInstance()->fetchAll(
            'SELECT guid, COUNT(*) AS duplicates FROM clients'
            . ' GROUP BY guid'
            . ' HAVING duplicates > 1'
        );

        if (!empty($duplicates)) {
            $log->error(
                "Duplicate guid were found in the 'clients' table:\n\n" .
                implode("\n", array_column($duplicates, 'guid')) . "\n\n"
                . "Please check https://support.plesk.com/hc/en-us/articles/360016801679 for more details."
            );
            $log->resultError();
        }
    }

    private function checkIsFirewallPackageConfigured()
    {
        if (!Util::isLinux()) {
            return;
        }

        $log = Log::getInstance("Checking if any rules are configured in the Firewall extension");
        $db = PleskDb::getInstance();
        if ($db->fetchOne("SHOW TABLES LIKE 'module_firewall_rules'")
            && $db->fetchOne("SELECT COUNT(*) FROM module_firewall_rules")
        ) {
            $message = "Plesk Firewall no longer stores its configuration in Plesk database since "
                . "Plesk Obsidian 18.0.52. Since you're upgrading to the latest version late, "
                . "if you wish to retain the Plesk Firewall extension and its configuration, "
                . "you need to follow additional steps after the upgrade. Please check "
                . "https://support.plesk.com/hc/en-us/articles/16198248236311 for more details.";
            $log->warning($message);
        }
    }
}

class PleskModule
{
    public static function isInstalledWatchdog()
    {
        return PleskModule::_isInstalled('watchdog');
    }

    public static function isInstalledFileServer()
    {
        return PleskModule::_isInstalled('fileserver');
    }

    public static function isInstalledFirewall()
    {
        return PleskModule::_isInstalled('firewall');
    }

    public static function isInstalledVpn()
    {
        return PleskModule::_isInstalled('vpn');
    }

    public static function isMultiServer()
    {
        return PleskModule::_isInstalled('plesk-multi-server') ||
            PleskModule::_isInstalled('plesk-multi-server-node');
    }

    protected static function _isInstalled($module)
    {
        $sql = "SELECT * FROM Modules WHERE name = '{$module}'";

        $pleskDb = PleskDb::getInstance();
        $row = $pleskDb->fetchRow($sql);

        return (empty($row) ? false : true);
    }
}

class PleskInstallation
{
    public static function validate()
    {
        if (!self::isInstalled()) {
            $log = Log::getInstance('Checking for Plesk installation');
            $log->step('Plesk installation is not found. You will have no problems with upgrade, go on and install '
                . PleskVersion::getLatestPleskVersionAsString() . ' (https://www.plesk.com/)');
            return;
        }
        self::detectVersion();
    }

    public static function isInstalled()
    {
        $rootPath = Util::getPleskRootPath();
        if (empty($rootPath) || !file_exists($rootPath)) {
            return false;
        }
        return true;
    }

    private static function detectVersion()
    {
        $log = Log::getInstance('Installed Plesk version/build: ' . PleskVersion::getVersionAndBuild(), false);

        $currentVersion = PleskVersion::getVersion();
        if (version_compare($currentVersion, PLESK_VERSION, 'eq')) {
            $err = 'You have already installed the latest version ' . PleskVersion::getLatestPleskVersionAsString() . '. ';
            $err .= 'Tool must be launched prior to upgrade to ' . PleskVersion::getLatestPleskVersionAsString() . ' for the purpose of getting a report on potential problems with the upgrade.';
            $log->info($err);
            exit(0);
        }

        if (!PleskVersion::isUpgradeSupportedVersion()) {
            $err = 'Unable to find Plesk 17.x. ';
            $err .= 'Tool must be launched prior to upgrade to ' . PleskVersion::getLatestPleskVersionAsString() . ' for the purpose of getting a report on potential problems with the upgrade.';
            fatal($err);
        }
    }
}

class PleskVersion
{
    const PLESK_17_MIN_VERSION = '13.0.0'; /* historically it has been started as 13.0 */

    const PLESK_17_MAX_VERSION = '17.9.13';

    const PLESK_18_MIN_VERSION = '18.0.14';

    public static function is17x_or_above()
    {
        return version_compare(self::getVersion(), self::PLESK_17_MIN_VERSION, '>=');
    }

    public static function is_below_17_5()
    {
        return version_compare(self::getVersion(), '17.5.0', '<');
    }

    public static function is_below_17_8()
    {
        return version_compare(self::getVersion(), '17.8.0', '<');
    }

    public static function is_below_17_9()
    {
        return version_compare(self::getVersion(), '17.9.0', '<');
    }

    public static function is_18_0_32_or_above()
    {
        return version_compare(self::getVersion(), '18.0.32', '>=');
    }

    public static function getVersion()
    {
        $version = self::getVersionAndBuild();
        if (!preg_match('/([0-9]+[.][0-9]+[.][0-9]+)/', $version, $matches)) {
            fatal("Incorrect Plesk version format. Current version: {$version}");
        }
        return $matches[1];
    }

    public static function getVersionAndBuild()
    {
        $versionPath = Util::getPleskRootPath().'/version';
        if (!file_exists($versionPath)) {
            fatal("Plesk version file is not exists $versionPath");
        }
        $version = file_get_contents($versionPath);
        $version = trim($version);
        return $version;
    }

    public static function getLatestPleskVersionAsString()
    {
        return 'Plesk ' . PLESK_VERSION;
    }

    public static function isUpgradeSupportedVersion()
    {
        return self::is17x_or_above();
    }
}

class Log
{
    private $errors;
    private $warnings;
    private $emergency;
    private $logfile;
    private $step;
    private $step_header;

    /** @var array */
    private $errorsContent = [];

    /** @var array */
    private $warningsContent = [];

    public static function getInstance($step_msg = '', $step_number = true)
    {
        static $_instance = null;
        if (is_null($_instance)) {
            $_instance = new Log();
        }
        if ($step_msg) {
            $_instance->step($step_msg, $step_number);
        }

        return $_instance;
    }

    private function __construct()
    {
        $this->log_init();
        @unlink($this->logfile);
    }

    private function log_init()
    {
        $this->step      = 0;
        $this->errors    = 0;
        $this->warnings  = 0;
        $this->emergency = 0;
        $this->logfile = LOG_PATH;
        $this->step_header = "Unknown step is running";
    }

    public function getErrors()
    {
        return $this->errors;
    }

    public function getWarnings()
    {
        return $this->warnings;
    }

    public function getEmergency()
    {
        return $this->emergency;
    }

    public function fatal($msg)
    {
        $this->errors++;

        $this->errorsContent[] = $msg;
        $content = $this->get_log_string($msg, 'FATAL_ERROR');
        fwrite(STDERR, $content);
        $this->write($content);
    }

    public function error($msg)
    {
        $this->errors++;

        $this->errorsContent[] = $msg;
        $content = $this->get_log_string($msg, 'ERROR');
        fwrite(STDERR, $content);
        $this->write($content);
    }

    public function warning($msg)
    {
        $this->warnings++;

        $this->warningsContent[] = $msg;
        $content = $this->get_log_string($msg, 'WARNING');
        fwrite(STDERR, $content);
        $this->write($content);
    }

    public function emergency($msg)
    {
        $this->emergency++;

        $this->errorsContent[] = $msg;
        $content = $this->get_log_string($msg, 'EMERGENCY');
        fwrite(STDERR, $content);
        $this->write($content);
    }

    public function step($msg, $useNumber=false)
    {
        $this->step_header = $msg;

        echo PHP_EOL;
        $this->write(PHP_EOL);

        if ($useNumber) {
            $msg = "STEP " . $this->step . ": {$msg}...";
            $this->step++;
        } else {
            $msg = "{$msg}...";
        }

        $this->info($msg);
    }

    public function resultOk()
    {
        $this->info('Result: OK');
    }

    public function resultWarning()
    {
        $this->info('Result: WARNING');
    }

    public function resultError()
    {
        $this->info('Result: ERROR');
    }

    public function info($msg)
    {
        $content = $this->get_log_string($msg, 'INFO');
        echo $content;
        $this->write($content);
    }

    public function debug($msg)
    {
        $this->write($this->get_log_string($msg, 'DEBUG'));
    }

    public function dumpStatistics()
    {
        $errors = $this->errors + $this->emergency;
        $str = "Errors found: $errors; Warnings found: {$this->warnings}";
        echo PHP_EOL . $str . PHP_EOL . PHP_EOL;
    }

    private function get_log_string($msg, $type)
    {
        if (getenv('VZ_UPGRADE_SCRIPT')) {
            switch ($type) {
                case 'FATAL_ERROR':
                case 'ERROR':
                case 'WARNING':
                case 'EMERGENCY':
                    $content = "[{$type}]: {$this->step_header} DESC: {$msg}" . PHP_EOL;
                    break;
                default:
                    $content = "[{$type}]: {$msg}" . PHP_EOL;
            }
        } else if (getenv('AUTOINSTALLER_VERSION')) {
            $content = "{$type}: {$msg}" . PHP_EOL;
        } else {
            $date = date('Y-m-d h:i:s');
            $content = "[{$date}][{$type}] {$msg}" . PHP_EOL;
        }

        return $content;
    }

    public function write($content, $file = null, $mode='a+')
    {
        $logfile = $file ? $file : $this->logfile;
        $fp = fopen($logfile, $mode);
        fwrite($fp, $content);
        fclose($fp);
    }

    private function getJsonFileName()
    {
        return (Util::isWindows() ?
                rtrim(Util::regPleskQuery('PRODUCT_DATA_D'), "\\") :
                Util::getSettingFromPsaConf('PRODUCT_ROOT_D')
            ) . '/var/' . LOG_JSON;
    }

    public function writeJsonFile()
    {
        $data = [
            'version' => PRE_UPGRADE_SCRIPT_VERSION,
            'errorsFound' => $this->errors + $this->emergency,
            'errors' => $this->errorsContent,
            'warningsFound' => $this->warnings,
            'warnings' => $this->warningsContent,
        ];
        file_put_contents($this->getJsonFileName(), json_encode($data));
    }
}

class PleskDb
{
    var $_db = null;

    public function __construct($dbParams)
    {
        switch($dbParams['db_type']) {
            case 'mysql':
                $this->_db = new DbMysql(
                    $dbParams['host'], $dbParams['login'], $dbParams['passwd'], $dbParams['db'], $dbParams['port']
                );
                break;

            case 'jet':
                $this->_db = new DbJet($dbParams['db']);
                break;

            case 'mssql':
                $this->_db = new DbMsSql(
                    $dbParams['host'], $dbParams['login'], $dbParams['passwd'], $dbParams['db'], $dbParams['port']
                );
                break;

            default:
                fatal("{$dbParams['db_type']} is not implemented yet");
                break;
        }
    }

    public static function getInstance()
    {
        global $options;
        static $_instance = array();

        $dbParams['db_type']= Util::getPleskDbType();
        $dbParams['db']     = Util::getPleskDbName();
        $dbParams['port']   = Util::getPleskDbPort();
        $dbParams['login']  = Util::getPleskDbLogin();
        $dbParams['passwd'] = Util::getPleskDbPassword($options->getDbPasswd());
        $dbParams['host']   = Util::getPleskDbHost();

        $dbId = md5(implode("\n", $dbParams));

        $_instance[$dbId] = new PleskDb($dbParams);

        return $_instance[$dbId];
    }

    function fetchOne($sql)
    {
        if (DEBUG) {
            $log = Log::getInstance();
            $log->info($sql);
        }
        return $this->_db->fetchOne($sql);
    }

    function fetchRow($sql)
    {
        $res = $this->fetchAll($sql);
        if (is_array($res) && isset($res[0])) {
            return $res[0];
        }
        return array();
    }

    function fetchAll($sql)
    {
        if (DEBUG) {
            $log = Log::getInstance();
            $log->info($sql);
        }
        return $this->_db->fetchAll($sql);
    }
}

class DbMysql
{
    var $_dbHandler;

    public function __construct($host, $user, $passwd, $database, $port)
    {
        if ( extension_loaded('mysql') ) {
            $this->_dbHandler = @mysql_connect("{$host}:{$port}", $user, $passwd);
            if (!is_resource($this->_dbHandler)) {
                $mysqlError = mysql_error();
                if (stristr($mysqlError, 'access denied for user')) {
                    $errMsg = 'Given <password> is incorrect. ' . $mysqlError;
                } else {
                    $errMsg = 'Unable to connect database. The reason of problem: ' . $mysqlError . PHP_EOL;
                }
                $this->_logError($errMsg);
            }
            @mysql_select_db($database, $this->_dbHandler);
        } else if ( extension_loaded('mysqli') ) {

            // forbid using MYSQLI_REPORT_STRICT to handle mysqli errors via error codes
            mysqli_report(MYSQLI_REPORT_ERROR);

            $this->_dbHandler = @mysqli_connect($host, $user, $passwd, $database, $port);
            if (!$this->_dbHandler) {
                $mysqlError = mysqli_connect_error();
                if (stristr($mysqlError, 'access denied for user')) {
                    $errMsg = 'Given <password> is incorrect. ' . $mysqlError;
                } else {
                    $errMsg = 'Unable to connect database. The reason of problem: ' . $mysqlError . PHP_EOL;
                }
                $this->_logError($errMsg);
            }
        } else {
            fatal("No MariaDB/MySQL extension is available");
        }
    }

    function fetchAll($sql)
    {
        if ( extension_loaded('mysql') ) {
            $res = mysql_query($sql, $this->_dbHandler);
            if (!is_resource($res)) {
                $this->_logError('Unable to execute query. Error: ' . mysql_error($this->_dbHandler));
            }
            $rowset = array();
            while ($row = mysql_fetch_assoc($res)) {
                $rowset[] = $row;
            }
            return $rowset;
        } else if ( extension_loaded('mysqli') ) {
            $res = $this->_dbHandler->query($sql);
            if ($res === false) {
                $this->_logError('Unable to execute query. Error: ' . mysqli_error($this->_dbHandler));
            }
            $rowset = array();
            while ($row = mysqli_fetch_assoc($res)) {
                $rowset[] = $row;
            }
            return $rowset;
        } else {
            fatal("No MariaDB/MySQL extension is available");
        }
    }

    function fetchOne($sql)
    {
        if ( extension_loaded('mysql') ) {
            $res = mysql_query($sql, $this->_dbHandler);
            if (!is_resource($res)) {
                $this->_logError('Unable to execute query. Error: ' . mysql_error($this->_dbHandler));
            }
            $row = mysql_fetch_row($res);
            return isset($row[0]) ? $row[0] : null;
        } else if ( extension_loaded('mysqli') ) {
            $res = $this->_dbHandler->query($sql);
            if ($res === false) {
                $this->_logError('Unable to execute query. Error: ' . mysqli_error($this->_dbHandler));
            }
            $row = mysqli_fetch_row($res);
            return isset($row[0]) ? $row[0] : null;
        } else {
            fatal("No MariaDB/MySQL extension is available");
        }
    }

    function query($sql)
    {
        if ( extension_loaded('mysql') ) {
            $res = mysql_query($sql, $this->_dbHandler);
            if ($res === false ) {
                $this->_logError('Unable to execute query. Error: ' . mysql_error($this->_dbHandler) );
            }
            return $res;
        } else if ( extension_loaded('mysqli') ) {
            $res = $this->_dbHandler->query($sql);
            if ($res === false ) {
                $this->_logError('Unable to execute query. Error: ' . mysqli_error($this->_dbHandler) );
            }
            return $res;
        } else {
            fatal("No MariaDB/MySQL extension is available");
        }
    }

    function _logError($message)
    {
        fatal("[MYSQL ERROR] $message");
    }
}

class DbClientMysql extends DbMysql
{
    var $errors = array();

    function _logError($message)
    {
        $message = "[MYSQL ERROR] $message";
        $log = Log::getInstance();
        $log->warning($message);
        $this->errors[] = $message;
    }

    function hasErrors() {
        return count($this->errors) > 0;
    }
}

class DbJet
{
    var $_dbHandler = null;

    public function __construct($dbPath)
    {
        $dsn = "Provider='Microsoft.Jet.OLEDB.4.0';Data Source={$dbPath}";
        $this->_dbHandler = new COM("ADODB.Connection", NULL, CP_UTF8);
        if (!$this->_dbHandler) {
            $this->_logError('Unable to init ADODB.Connection');
        }

        $this->_dbHandler->open($dsn);
    }

    function fetchAll($sql)
    {
        $result_id = $this->_dbHandler->execute($sql);
        if (!$result_id) {
            $this->_logError('Unable to execute sql query ' . $sql);
        }
        if ($result_id->BOF && !$result_id->EOF) {
            $result_id->MoveFirst();
        }
        if ($result_id->EOF) {
            return array();
        }

        $rowset = array();
        while(!$result_id->EOF) {
            $row = array();
            for ($i=0;$i<$result_id->Fields->count;$i++) {
                $field = $result_id->Fields($i);
                $row[$field->Name] = (string)$field->value;
            }
            $result_id->MoveNext();
            $rowset[] = $row;
        }
        return $rowset;
    }

    function fetchOne($sql)
    {
        $result_id = $this->_dbHandler->execute($sql);
        if (!$result_id) {
            $this->_logError('Unable to execute sql query ' . $sql);
        }
        if ($result_id->BOF && !$result_id->EOF) {
            $result_id->MoveFirst();
        }
        if ($result_id->EOF) {
            return null;
        }
        $field = $result_id->Fields(0);
        $result = $field->value;

        return (string)$result;
    }

    function _logError($message)
    {
        fatal("[JET ERROR] $message");
    }
}

class DbMsSql extends DbJet
{
    public function __construct($host, $user, $passwd, $database, $port)
    {
        $dsn = "Provider=SQLOLEDB.1;Initial Catalog={$database};Data Source={$host}";
        $this->_dbHandler = new COM("ADODB.Connection", NULL, CP_UTF8);
        if (!$this->_dbHandler) {
            $this->_logError('Unable to init ADODB.Connection');
        }
        $this->_dbHandler->open($dsn, $user, $passwd);
    }

    function _logError($message)
    {
        fatal("[MSSQL ERROR] $message");
    }
}

class Util
{
    const DSN_INI_PATH_UNIX = '/etc/psa/private/dsn.ini';

    /** @var array */
    private static $_dsnIni;

    public static function isWindows()
    {
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            return true;
        }
        return false;
    }

    public static function isLinux()
    {
        return !Util::isWindows();
    }

    public static function isVz()
    {
        $vz = false;
        if (Util::isLinux()) {
            if (file_exists('/proc/vz/veredir')) {
                $vz = true;
            }
        } else {
            $reg = 'REG QUERY "HKLM\SOFTWARE\SWsoft\Virtuozzo" 2>nul';
            Util::exec($reg, $code);
            if ($code==0) {
                $vz = true;
            }
        }
        return $vz;
    }

    public static function getArch()
    {
        global $arch;
        if (!empty($arch))
            return $arch;

        $arch = 'i386';
        if (Util::isLinux()) {
            $cmd = 'uname -m';
            $x86_64 = 'x86_64';
            $output = Util::exec($cmd, $code);
            if (!empty($output) && stristr($output, $x86_64)) {
                $arch = 'x86_64';
            }
        } else {
            $arch = 'x86_64';
        }
        return $arch;
    }

    public static function getHostname()
    {
        if (Util::isLinux()) {
            $cmd = 'hostname -f';
        } else {
            $cmd = 'hostname';
        }
        $hostname = Util::exec($cmd, $code);

        if (empty($hostname)) {
            $err = 'Command: ' . $cmd . ' returns: ' . $hostname . "\n";
            $err .= 'Hostname is not defined and configured. Unable to get hostname. Server should have properly configured hostname and it should be resolved locally.';
            fatal($err);
        }

        return $hostname;
    }

    public static function getIPList($lo=false)
    {
        if (Util::isLinux()) {
            $ipList = Util::getIPv4ListOnLinux();
            foreach ($ipList as $key => $ip) {
                if (!$lo && substr($ip, 0, 3) == '127') {
                    unset($ipList[$key]);
                    continue;
                }
                trim($ip);
            }
            $ipList = array_values($ipList);
        } else {
            $cmd = 'hostname';
            $hostname = Util::exec($cmd, $code);
            $ip = gethostbyname($hostname);
            $res = ($ip != $hostname) ? true : false;
            if (!$res) {
                fatal('Unable to retrieve IP address');
            }
            $ipList = array(trim($ip));
        }
        return $ipList;
    }

    public static function getIPv6ListOnLinux()
    {
        return Util::grepCommandOutput(array(
            array('bin' => 'ip', 'command' => '%PATH% addr list', 'regexp' => '#inet6 ([^ /]+)#'),
            array('bin' => 'ifconfig', 'command' => '%PATH% -a', 'regexp' => '#inet6 (?:addr: ?)?([A-F0-9:]+)#i'),
        ));
    }

    public static function getIPv4ListOnLinux()
    {
        $commands = array(
            array('bin' => 'ip', 'command' => '%PATH% addr list', 'regexp' => '#inet ([^ /]+)#'),
            array('bin' => 'ifconfig', 'command' => '%PATH% -a', 'regexp' => '#inet (?:addr: ?)?([\d\.]+)#'),
        );
        if (!($list = Util::grepCommandOutput($commands))) {
            fatal('Unable to get IP address');
        }
        return $list;
    }

    public static function grepCommandOutput($cmds)
    {
        foreach ($cmds as $cmd) {
            if ($fullPath = Util::lookupCommand($cmd['bin'])) {
                $output = Util::exec(str_replace("%PATH%", $fullPath, $cmd['command']), $code);
                if (preg_match_all($cmd['regexp'], $output, $matches)) {
                    return $matches[1];
                }
            }
        }
        return false;
    }

    public static function getIPListOnWindows()
    {
        $cmd = 'wmic.exe path win32_NetworkAdapterConfiguration get IPaddress';
        $output = Util::exec($cmd, $code);
        if (!preg_match_all('/"(.*?)"/', $output, $matches)) {
            fatal('Unable to get IP address');
        }
        return $matches[1];
    }

    public static function getPleskRootPath()
    {
        global $_pleskRootPath;
        if (empty($_pleskRootPath)) {
            if (Util::isLinux()) {
                if (PleskOS::isDebLike()) {
                    $_pleskRootPath = '/opt/psa';
                } else {
                    $_pleskRootPath = '/usr/local/psa';
                }
            }
            if (Util::isWindows()) {
                $_pleskRootPath = Util::regPleskQuery('PRODUCT_ROOT_D', true);
            }
        }
        return $_pleskRootPath;
    }

    public static function getPleskDbName()
    {
        $dbName = 'psa';
        if (Util::isWindows()) {
            $dbName = Util::regPleskQuery('mySQLDBName');
        } else {
            $dsnDbname = Util::_getDsnConfigValue('dbname');
            if ($dsnDbname) {
                $dbName = $dsnDbname;
            }
        }
        return $dbName;
    }

    public static function getPleskDbLogin()
    {
        $dbLogin = 'admin';
        if (Util::isWindows()) {
            $dbLogin = Util::regPleskQuery('PLESK_DATABASE_LOGIN');
        } else {
            $dsnLogin = Util::_getDsnConfigValue('username');
            if ($dsnLogin) {
                $dbLogin = $dsnLogin;
            }
        }
        return $dbLogin;
    }

    public static function getPleskDbPassword($dbPassword)
    {
        if (Util::isLinux()) {
            $dsnPassword = Util::_getDsnConfigValue('password');
            if ($dsnPassword) {
                $dbPassword = $dsnPassword;
            }
        }
        return $dbPassword;
    }

    public static function getPleskDbType()
    {
        $dbType = 'mysql';
        if (Util::isWindows()) {
            $dbType = strtolower(Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME'));
        }
        return $dbType;
    }

    public static function getPleskDbHost()
    {
        $dbHost = 'localhost';
        if (Util::isWindows()) {
            $dbProvider = strtolower(Util::regPleskQuery('PLESK_DATABASE_PROVIDER_NAME'));
            if ($dbProvider == 'mysql' || $dbProvider == 'mssql') {
                $dbHost = Util::regPleskQuery('MySQL_DB_HOST');
            }
        } else {
            $dsnHost = Util::_getDsnConfigValue('host');
            if ($dsnHost) {
                $dbHost = $dsnHost;
            }
        }
        return $dbHost;
    }

    public static function getPleskDbPort()
    {
        $dbPort = '3306';
        if (Util::isWindows()) {
            $dbPort = Util::regPleskQuery('MYSQL_PORT');
        } else {
            $dsnPort = Util::_getDsnConfigValue('port');
            if ($dsnPort) {
                $dbPort = $dsnPort;
            }
        }
        return $dbPort;
    }

	private static function _getDsnConfigValue($param)
    {
        if (Util::isWindows()) {
            return null;
        }

        if (is_null(self::$_dsnIni)) {
            if (!is_file(self::DSN_INI_PATH_UNIX)) {
                self::$_dsnIni = false;
                return null;
            }
            self::$_dsnIni = parse_ini_file(self::DSN_INI_PATH_UNIX, true);
        }

        if (!self::$_dsnIni) {
            return null;
        }
        if (!array_key_exists('plesk', self::$_dsnIni)) {
            return null;
        }
        if (!array_key_exists($param, self::$_dsnIni['plesk'])) {
            return null;
        }
        return self::$_dsnIni['plesk'][$param];
    }

    public static function regPleskQuery($key, $returnResult=false)
    {
        $reg = 'REG QUERY "HKLM\SOFTWARE\Wow6432Node\Plesk\Psa Config\Config" /v '.$key;
        $output = Util::exec($reg, $code);

        if ($code) {
            $log = Log::getInstance();
            $log->info($reg);
            $log->info($output);
            if ($returnResult) {
                return false;
            } else {
                fatal("Unable to get '$key' from registry");
            }
        }

        if (!preg_match("/\w+\s+REG_SZ\s+(.*)/i", trim($output), $matches)) {
            fatal('Unable to macth registry value by key '.$key.'. Output: ' .  trim($output));
        }

        return $matches[1];
    }

    public static function regQuery($path, $key, $returnResult = false)
    {
        $reg = 'REG QUERY "HKLM\SOFTWARE\Wow6432Node' . $path .  '" '.$key;
        $output = Util::exec($reg, $code);

        if ($code) {
            $log = Log::getInstance();
            $log->info($reg);
            $log->info($output);
            if ($returnResult) {
                return false;
            } else {
                fatal("Unable to get '$key' from registry");
            }
        }

        if (!preg_match("/\s+REG_SZ(\s+)?(.*)/i", trim($output), $matches)) {
            fatal('Unable to match registry value by key '.$key.'. Output: ' .  trim($output));
        }

        return $matches[2];
    }

    public static function lookupCommand($cmd, $exit = false, $path = '/bin:/usr/bin:/usr/local/bin:/usr/sbin:/sbin:/usr/local/sbin')
    {
        $dirs = explode(':', $path);
        foreach ($dirs as $dir) {
            $util = $dir . '/' . $cmd;
            if (is_executable($util)) {
                return $util;
            }
        }
        if ($exit) {
            fatal("{$cmd}: command not found");
        }
        return false;
    }

    public static function getSystemDisk()
    {
        $cmd = 'echo %SYSTEMROOT%';
        $output = Util::exec($cmd, $code);
        return substr($output, 0, 3);
    }

    public static function getSystemRoot()
    {
        $cmd = 'echo %SYSTEMROOT%';
        $output = Util::exec($cmd, $code);
        return $output;
    }

    public static function getFileVersion($file)
    {
        $fso = new COM("Scripting.FileSystemObject");
        $version = $fso->GetFileVersion($file);
        $fso = null;
        return $version;
    }

    public static function isUnknownISAPIfilters()
    {
        if (PleskVersion::is17x_or_above()) {
            return false;
        }

        $log = Log::getInstance();

        $isUnknownISAPI = false;
        $knownISAPI = array ("ASP\\.Net.*", "sitepreview", "COMPRESSION", "jakarta");

        foreach ($knownISAPI as &$value) {
            $value = strtoupper($value);
        }
        $cmd='cscript ' . Util::getSystemDisk() . 'inetpub\AdminScripts\adsutil.vbs  ENUM W3SVC/FILTERS';
        $output = Util::exec($cmd,  $code);

        if ($code!=0) {
            $log->info("Unable to get ISAPI filters. Error: " . $output);
            return false;
        }
        if (!preg_match_all('/FILTERS\/(.*)]/', trim($output), $matches)) {
            $log->info($output);
            $log->info("Unable to get ISAPI filters from output: " . $output);
            return false;
        }
        foreach ($matches[1] as $ISAPI) {
            $valid = false;
            foreach ($knownISAPI as $knownPattern) {
                if (preg_match("/$knownPattern/i", $ISAPI)) {
                    $valid = true;
                    break;
                }
            }
            if (! $valid ) {
                $log->warning("Unknown ISAPI filter detected in IIS: " . $ISAPI);
                $isUnknownISAPI = true;
            }
        }

        return $isUnknownISAPI;
    }

    /**
     * @return string
     */
    public static function getMySQLServerVersion()
    {
        $credentials = Util::getDefaultClientMySQLServerCredentials();

        if (preg_match('/AES-128-CBC/', $credentials['admin_password'])) {
            Log::getInstance()->info('The administrator\'s password for the default MariaDB/MySQL server is encrypted.');

            return '';
        }

        $mysql = new DbClientMysql(
            $credentials['host'],
            $credentials['admin_login'],
            $credentials['admin_password'],
            'information_schema',
            $credentials['port']
        );

        if (!$mysql->hasErrors()) {
            $sql = 'select version()';
            $mySQLversion = $mysql->fetchOne($sql);
            if (!preg_match("/(\d{1,})\.(\d{1,})\.(\d{1,})/", trim($mySQLversion), $matches)) {
                fatal('Unable to match MariaDB/MySQL server version.');
            }

            return $matches[0];
        }

        return '';
    }

    public static function getDefaultClientMySQLServerCredentials()
    {
        $db = PleskDb::getInstance();
        $sql = "SELECT val FROM misc WHERE param='default_server_mysql'";
        $defaultServerMysqlId = $db->fetchOne($sql);
        if ($defaultServerMysqlId) {
            $where = "id={$defaultServerMysqlId}";
        } else {
            $where = "type='mysql' AND host='localhost'";
        }
        $sql = "SELECT ds.host, ds.port, ds.admin_login, ds.admin_password FROM DatabaseServers ds WHERE {$where}";
        $clientDBServerCredentials = $db->fetchAll($sql)[0];
        if ($clientDBServerCredentials['host'] === 'localhost' && Util::isLinux()) {
            $clientDBServerCredentials['admin_password'] = Util::retrieveAdminMySQLDbPassword();
        }
        if (empty($clientDBServerCredentials['port'])) {
            $clientDBServerCredentials['port'] = self::getPleskDbPort();
        }

        return $clientDBServerCredentials;
    }

    public static function retrieveAdminMySQLDbPassword()
    {
        return Util::isLinux()
            ? trim( Util::readfile("/etc/psa/.psa.shadow") )
            : null;
    }

    public static function exec($cmd, &$code)
    {
        $log = Log::getInstance();

        if (!$cmd) {
            $log->info('Unable to execute a blank command. Please see ' . LOG_PATH . ' for details.');

            $debugBacktrace = "";
            foreach (debug_backtrace() as $i => $obj) {
                $debugBacktrace .= "#{$i} {$obj['file']}:{$obj['line']} {$obj['function']} ()\n";
            }
            $log->debug("Unable to execute a blank command. The stack trace:\n{$debugBacktrace}");
            $code = 1;
            return '';
        }
        exec($cmd, $output, $code);
        return trim(implode("\n", $output));
    }

    public static function readfile($file)
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $lines = file($file);
        return $lines === false
            ? null
            : trim(implode("\n", $lines));
    }

    public static function readfileToArray($file)
    {
        if (!is_file($file) || !is_readable($file)) {
            return null;
        }
        $lines = file($file);
        return $lines === false
            ? null
            : $lines;
    }

    public static function getSettingFromPsaConf($setting)
    {
        $file = '/etc/psa/psa.conf';
        if (!is_file($file) || !is_readable($file))
            return null;
        $lines = file($file);
        if ($lines === false)
            return null;
        foreach ($lines as $line) {
            if (preg_match("/^{$setting}\s.*/", $line, $match_setting)) {
                if (preg_match("/[\s].*/i", $match_setting[0], $match_value)) {
                    $value = trim($match_value[0]);
                    return $value;
                }
            }
        }
        return null;
    }

    public static function getPhpIni()
    {
        if (Util::isLinux()) {
            // Debian/Ubuntu  /etc/php5/apache2/php.ini /etc/php5/conf.d/
            // SuSE  /etc/php5/apache2/php.ini /etc/php5/conf.d/
            // CentOS 4/5 /etc/php.ini /etc/php.d
            if (PleskOS::isRedHatLike()) {
                $phpini = Util::readfileToArray('/etc/php.ini');
            } else {
                $phpini = Util::readfileToArray('/etc/php5/apache2/php.ini');
            }
        }

        return $phpini;
    }

    public static function getUserBeanCounters()
    {
        if (!Util::isLinux()) {

            return false;
        }
        $user_beancounters = array();
        $ubRaw = Util::readfileToArray('/proc/user_beancounters');

        if (!$ubRaw) {

            return false;
        }
        for ($i=2; $i<=count($ubRaw)-1; $i++) {

            if (preg_match('/^.+?:?.+?\b(\w+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)\s+(\d+)/', $ubRaw[$i], $limit_name)) {

                $user_beancounters[trim($limit_name[1])] = array(
                    'held' => (int)$limit_name[2],
                    'maxheld' => (int)$limit_name[3],
                    'barrier' => (int)$limit_name[4],
                    'limit' => (int)$limit_name[5],
                    'failcnt' => (int)$limit_name[6]
                );
            }
        }

        return $user_beancounters;
    }
}

class PackageManager
{
    public static function buildListCmdLine($glob)
    {
        if (PleskOS::isRedHatLike()) {
            $cmd = "rpm -qa --queryformat '%{NAME} %{VERSION}-%{RELEASE} %{ARCH}\\n'";
        } elseif (PleskOS::isDebLike()) {
            $cmd = "dpkg-query --show --showformat '\${Package} \${Version} \${Architecture}\\n'";
        } else {
            return false;
        }

        if (!empty($glob)) {
            $cmd .= " '" . $glob . "' 2>/dev/null";
        }

        return $cmd;
    }

    /*
     * Fetches a list of installed packages that match given criteria.
     * string $glob - Glob (wildcard) pattern for coarse-grained packages selection from system package management backend. Empty $glob will fetch everything.
     * string $regexp - Package name regular expression for a fine-grained filtering of the results.
     * returns array of hashes with keys 'name', 'version' and 'arch', or false on error.
     */
    public static function listInstalled($glob, $regexp = null)
    {
        $cmd = PackageManager::buildListCmdLine($glob);
        if (!$cmd) {
            return array();
        }

        $output = Util::exec($cmd, $code);
        if ($code != 0) {
            return false;
        }

        $packages = array();
        $lines = explode("\n", $output);
        foreach ($lines as $line) {
            @list($pkgName, $pkgVersion, $pkgArch) = explode(" ", $line);
            if (empty($pkgName) || empty($pkgVersion) || empty($pkgArch))
                continue;
            if (!empty($regexp) && !preg_match($regexp, $pkgName))
                continue;
            $packages[] = array(
                'name' => $pkgName,
                'version' => $pkgVersion,
                'arch' => $pkgArch
            );
        }

        return $packages;
    }

    public static function isInstalled($glob, $regexp = null)
    {
        $packages = PackageManager::listInstalled($glob, $regexp);
        return !empty($packages);
    }
}

class Package
{
    function getManager($field, $package)
    {
        $redhat = 'rpm -q --queryformat \'%{' . $field . '}\n\' ' . $package;
        $debian = 'dpkg-query --show --showformat=\'${' . $field . '}\n\' '. $package . ' 2> /dev/null';

        if (PleskOS::isRedHatLike()) {
            $manager = $redhat;
        } elseif (PleskOS::isDebLike()) {
            $manager = $debian;
        } else {
            return false;
        }

        return $manager;
    }

    /* DPKG doesn't supports ${Release}
     *
     */

    function getRelease($package)
    {
        $manager = Package::getManager('Release', $package);

        if (!$manager) {
            return false;
        }

        $release = Util::exec($manager, $code);
        if (!$code === 0) {
            return false;
        }
        return $release;
    }

    function getVersion($package)
    {
        $manager = Package::getManager('Version', $package);

        if (!$manager) {
            return false;
        }

        $version = Util::exec($manager, $code);
        if (!$code === 0) {
            return false;
        }
        return $version;
    }

}

class PleskOS
{
    public static function isDebLike()
    {
        return is_file("/etc/debian_version");
    }

    public static function isRedHatLike()
    {
        return is_file("/etc/redhat-release");
    }

    public static function catEtcIssue()
    {
        $cmd = 'cat /etc/issue';
        $output = Util::exec($cmd, $code);

        return $output;
    }

    public static function detectSystem()
    {
        $log = Log::getInstance('Detect system configuration');
        $log->info('OS: ' . (Util::isLinux() ? PleskOS::catEtcIssue() : 'Windows'));
        $log->info('Arch: ' . Util::getArch());
    }
}

class PleskValidator
{
    public static function validateIPv4($value)
    {
        $ip2long = ip2long($value);
        if ($ip2long === false) {
            return false;
        }

        return $value == long2ip($ip2long);
    }

    public static function validateIPv6($value)
    {
        if (strlen($value) < 3) {
            return $value == '::';
        }

        if (strpos($value, '.')) {
            $lastcolon = strrpos($value, ':');
            if (!($lastcolon && PleskValidator::validateIPv4(substr($value, $lastcolon + 1)))) {
                return false;
            }

            $value = substr($value, 0, $lastcolon) . ':0:0';
        }

        if (strpos($value, '::') === false) {
            return preg_match('/\A(?:[a-f0-9]{1,4}:){7}[a-f0-9]{1,4}\z/i', $value);
        }

        $colonCount = substr_count($value, ':');
        if ($colonCount < 8) {
            return preg_match('/\A(?::|(?:[a-f0-9]{1,4}:)+):(?:(?:[a-f0-9]{1,4}:)*[a-f0-9]{1,4})?\z/i', $value);
        }

        // special case with ending or starting double colon
        if ($colonCount == 8) {
            return preg_match('/\A(?:::)?(?:[a-f0-9]{1,4}:){6}[a-f0-9]{1,4}(?:::)?\z/i', $value);
        }

        return false;
    }
}

class CheckRequirements
{
    function validate()
    {
        if (!PleskInstallation::isInstalled()) {
            //:INFO: skip chking mysql extension if plesk is not installed
            return;
        }

        $reqExts = array();
        foreach ($reqExts as $name) {
            $status = extension_loaded($name);
            if (!$status) {
                $this->_fail("PHP extension {$name} is not installed");
            }
        }
    }

    function _fail($errMsg)
    {
        echo '===Checking requirements===' . PHP_EOL;
        echo PHP_EOL . 'Error: ' . $errMsg . PHP_EOL;
        exit(1);
    }
}

class GetOpt
{
    var $_argv;
    var $_adminDbPasswd;

    public function __construct()
    {
        $this->_argv = $_SERVER['argv'];
        if (empty($this->_argv[1]) && Util::isLinux()) {
            $this->_adminDbPasswd = Util::retrieveAdminMySQLDbPassword();
        } else {
            $this->_adminDbPasswd = $this->_argv[1];
        }
    }

    public function validate()
    {
        if (empty($this->_adminDbPasswd) && PleskInstallation::isInstalled()) {
            echo 'Please specify Plesk database password';
            $this->_helpUsage();
        }
    }

    public function getDbPasswd()
    {
        return $this->_adminDbPasswd;
    }

    public function _helpUsage()
    {
        echo PHP_EOL . "Usage: {$this->_argv[0]} <plesk_db_admin_password>" . PHP_EOL;
        exit(1);
    }
}

function fatal($msg)
{
    $log = Log::getInstance();
    $log->fatal($msg);
    exit(1);
}

$log = Log::getInstance();

//:INFO: Validate options
$options = new GetOpt();
$options->validate();

//:INFO: Validate PHP requirements, need to make sure that PHP extensions are installed
$checkRequirements = new CheckRequirements();
$checkRequirements->validate();

//:INFO: Validate Plesk installation
PleskInstallation::validate();

//:INFO: Detect system
$pleskOs = new PleskOS();
$pleskOs->detectSystem();

//:INFO: Need to make sure that given db password is valid
if (PleskInstallation::isInstalled()) {
    $log->step('Validating the database password');
    $pleskDb = PleskDb::getInstance();
    $log->resultOk();
}

//:INFO: Dump script version
$log->step('Pre-Upgrade analyzer version: ' . PRE_UPGRADE_SCRIPT_VERSION);

//:INFO: Validate known OS specific issues with recommendation to avoid bugs in Plesk
$pleskKnownIssues = new Plesk17KnownIssues();
$pleskKnownIssues->validate();

$plesk175Requirements = new Plesk175Requirements();
$plesk175Requirements->validate();

$plesk178Requirements = new Plesk178Requirements();
$plesk178Requirements->validate();

$plesk18Requirements = new Plesk18Requirements();
$plesk18Requirements->validate();

$log->dumpStatistics();
$log->writeJsonFile();

if ($log->getEmergency() > 0) {
    exit(2);
}

if ($log->getErrors() > 0 || $log->getWarnings() > 0) {
    exit(1);
}
// vim:set et ts=4 sts=4 sw=4:
