<?php

class BinApache
{
    const SERVICE_NAME = 'neardapache';
    const SERVICE_PARAMS = '-k runservice';
    
    const ROOT_CFG_VERSION = 'apacheVersion';
    const ROOT_CFG_LAUNCH_STARTUP = 'apacheLaunchStartup';
    
    const LOCAL_CFG_EXE = 'apacheExe';
    const LOCAL_CFG_CONF = 'apacheConf';
    const LOCAL_CFG_PORT = 'apachePort';
    const LOCAL_CFG_SSL_PORT = 'apacheSslPort';
    const LOCAL_CFG_OPENSSL_EXE = 'apacheOpensslExe';
    
    const CMD_VERSION_NUMBER = '-v';
    const CMD_COMPILE_SETTINGS = '-V';
    const CMD_COMPILED_MODULES = '-l';
    const CMD_CONFIG_DIRECTIVES = '-L';
    const CMD_VHOSTS_SETTINGS = '-S';
    const CMD_LOADED_MODULES = '-M';
    const CMD_SYNTAX_CHECK = '-t';
    
    private $name;
    private $version;
    private $launchStartup;
    
    private $rootPath;
    private $currentPath;
    private $neardConf;
    private $neardConfRaw;
    
    private $modulesPath;
    private $sslConf;
    private $accessLog;
    private $rewriteLog;
    private $errorLog;
    
    private $exe;
    private $conf;
    private $port;
    private $sslPort;
    private $opensslExe;
    
    private $service;
    
    public function __construct($rootPath, $version=null)
    {
        Util::logInitClass($this);
        $this->reload($rootPath);
    }
    
    public function reload($rootPath = null)
    {
        global $neardBs, $neardConfig, $neardLang;
        
        $this->name = $neardLang->getValue(Lang::APACHE);
        $this->version = $neardConfig->getRaw(self::ROOT_CFG_VERSION);
        $this->launchStartup = $neardConfig->getRaw(self::ROOT_CFG_LAUNCH_STARTUP) == Config::ENABLED;
        
        $this->rootPath = $rootPath == null ? $this->rootPath : $rootPath;
        $this->currentPath = $this->rootPath . '/apache' . $this->version;
        $this->neardConf = $this->currentPath . '/neard.conf';
        
        $this->modulesPath = $this->currentPath . '/modules';
        $this->sslConf = $this->currentPath . '/conf/extra/httpd-ssl.conf';
        $this->accessLog = $neardBs->getLogsPath() . '/apache_access.log';
        $this->rewriteLog = $neardBs->getLogsPath() . '/apache_rewrite.log';
        $this->errorLog = $neardBs->getLogsPath() . '/apache_error.log';
        
        if (!is_dir($this->currentPath)) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_FILE_NOT_FOUND), $this->name . ' ' . $this->version, $this->currentPath));
            return;
        }
        if (!is_file($this->neardConf)) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_CONF_NOT_FOUND), $this->name . ' ' . $this->version, $this->neardConf));
            return;
        }
        if (!is_file($this->sslConf)) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_CONF_NOT_FOUND), $this->name . ' ' . $this->version, $this->sslConf));
            return;
        }
        
        $this->neardConfRaw = parse_ini_file($this->neardConf);
        if ($this->neardConfRaw !== false) {
            $this->exe = $this->currentPath . '/' . $this->neardConfRaw[self::LOCAL_CFG_EXE];
            $this->conf = $this->currentPath . '/' . $this->neardConfRaw[self::LOCAL_CFG_CONF];
            $this->port = $this->neardConfRaw[self::LOCAL_CFG_PORT];
            $this->sslPort = $this->neardConfRaw[self::LOCAL_CFG_SSL_PORT];
            $this->opensslExe = $this->currentPath . '/' . $this->neardConfRaw[self::LOCAL_CFG_OPENSSL_EXE];
        }
        
        if (!is_file($this->exe)) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_EXE_NOT_FOUND), $this->name . ' ' . $this->version, $this->exe));
            return;
        }
        if (!is_file($this->conf)) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_CONF_NOT_FOUND), $this->name . ' ' . $this->version, $this->conf));
            return;
        }
        if (!is_numeric($this->port) || $this->port <= 0) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_INVALID_PARAMETER), self::LOCAL_CFG_PORT, $this->port));
            return;
        }
        if (!is_numeric($this->sslPort) || $this->sslPort <= 0) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_INVALID_PARAMETER), self::LOCAL_CFG_SSL_PORT, $this->sslPort));
            return;
        }
        if (!is_file($this->opensslExe)) {
            Util::logError(sprintf($neardLang->getValue(Lang::ERROR_EXE_NOT_FOUND), $this->name . ' ' . $this->version, $this->opensslExe));
            return;
        }
        
        $this->service = new Win32Service(self::SERVICE_NAME);
        $this->service->setDisplayName(APP_TITLE . ' ' . $this->getName() . ' ' . $this->version);
        $this->service->setBinPath($this->exe);
        $this->service->setParams(self::SERVICE_PARAMS);
        $this->service->setStartType(Win32Service::SERVICE_DEMAND_START);
        $this->service->setErrorControl(Win32Service::SERVER_ERROR_NORMAL);
    }
    
    public function __toString()
    {
        return $this->getName();
    }
    
    private function replace($key, $value)
    {
        $this->replaceAll(array($key => $value));
    }
    
    private function replaceAll($params)
    {
        $content = file_get_contents($this->neardConf);
        
        foreach ($params as $key => $value) {
            $content = preg_replace('|' . $key . ' = .*|', $key . ' = ' . '"' . $value.'"' , $content);
            $this->neardConfRaw[$key] = $value;
        }
    
        file_put_contents($this->neardConf, $content);
    }
    
    public function changePort($port, $checkUsed = false, $wbProgressBar = null)
    {
        global $neardBs, $neardCore, $neardBins, $neardWinbinder;
        
        if (!Util::isValidPort($port)) {
            Util::logError($this->getName() . ' port not valid: ' . $port);
            return false;
        }
        
        $port = intval($port);
        $neardWinbinder->incrProgressBar($wbProgressBar);
        
        $isPortInUse = Util::isPortInUse($port);
        if (!$checkUsed || $isPortInUse === false) {
            // bootstrap
            Util::replaceDefine($neardCore->getBootstrapFilePath(), 'CURRENT_APACHE_PORT', intval($port));
            $neardWinbinder->incrProgressBar($wbProgressBar);
            
            // neard.conf
            $this->setPort($port);
            $neardWinbinder->incrProgressBar($wbProgressBar);
            
            // httpd.conf
            Util::replaceInFile($this->getConf(), array(
                '/^Listen\s(\d+)/' => 'Listen ' . $port,
                '/^ServerName\s+([a-zA-Z0-9.]+):(\d+)/' => 'ServerName {{1}}:' . $port,
                '/^NameVirtualHost\s+([a-zA-Z0-9.*]+):(\d+)/' => 'NameVirtualHost {{1}}:' . $port,
                '/^<VirtualHost\s+([a-zA-Z0-9.*]+):(\d+)>/' => '<VirtualHost {{1}}:' . $port . '>'
            ));
            $neardWinbinder->incrProgressBar($wbProgressBar);
            
            // vhosts
            foreach ($this->getVhosts() as $vhost) {
                Util::replaceInFile($neardBs->getVhostsPath() . '/' . $vhost . '.conf', array(
                    '/^<VirtualHost\s+([a-zA-Z0-9.*]+):(\d+)>$/' => '<VirtualHost {{1}}:' . $port . '>$'
                ));
            }
            $neardWinbinder->incrProgressBar($wbProgressBar);
            
            // .htaccess
            Util::replaceInFile($neardBs->getWwwPath() . '/.htaccess', array(
                '/(.*)http:\/\/localhost(.*)/' => '{{1}}http://localhost' . ($port != 80 ? ':' . $port : '') . '/$1 [QSA,R=301,L]',
            ));
            $neardWinbinder->incrProgressBar($wbProgressBar);
            
            return true;
        }
        
        Util::logDebug($this->getName() . ' port in used: ' . $port . ' - ' . $isPortInUse);
        return $isPortInUse;
    }
    
    public function checkPort($port, $ssl = false, $showWindow = false)
    {
        global $neardBs, $neardLang, $neardWinbinder;
        $boxTitle = sprintf($neardLang->getValue(Lang::CHECK_PORT_TITLE), $this->getName(), $port);
        
        if (!Util::isValidPort($port)) {
            Util::logError($this->getName() . ' port not valid: ' . $port);
            return false;
        }
        
        $headers = Util::getApacheHeaders('http' . ($ssl ? 's' : '') . '://localhost:' . $port);
        if (!empty($headers)) {
            foreach ($headers as $row) {
                if (Util::startWith($row, 'Server: ')) {
                    Util::logDebug($this->getName() . ' port ' . $port . ' is used by: ' . $this->getName() . ' ' . str_replace('Server: ', '', trim($row)));
                    if ($showWindow) {
                        $neardWinbinder->messageBoxInfo(
                            sprintf($neardLang->getValue(Lang::PORT_USED_BY), $port, str_replace('Server: ', '', trim($row))),
                            $boxTitle
                        );
                    }
                    return true;
                }
            }
            Util::logDebug($this->getName() . ' port ' . $port . ' is used by another application');
            if ($showWindow) {
                $neardWinbinder->messageBoxWarning(
                    sprintf($neardLang->getValue(Lang::PORT_NOT_USED_BY), $port),
                    $boxTitle
                );
            }
        } else {
            Util::logDebug($this->getName() . ' port ' . $port . ' is not used');
            if ($showWindow) {
                $neardWinbinder->messageBoxError(
                    sprintf($neardLang->getValue(Lang::PORT_NOT_USED), $port),
                    $boxTitle
                );
            }
        }
        
        return false;
    }
    
    public function switchVersion($version, $showWindow = false)
    {
        global $neardBs, $neardCore, $neardLang, $neardBins, $neardWinbinder;
        Util::logDebug('Switch Apache version to ' . $version);
        
        $boxTitle = sprintf($neardLang->getValue(Lang::SWITCH_VERSION_TITLE), $this->getName(), $version);
        
        $apacheConf = str_replace('apache' . $this->getVersion(), 'apache' . $version, $this->getConf());
        $neardConf = str_replace('apache' . $this->getVersion(), 'apache' . $version, $this->neardConf);
        
        $tsDll = $neardBins->getPhp()->getTsDll();
        $apachePhpModuleName = $tsDll !== false ? substr($tsDll, 0, 4) . '_module' : null;
        $apachePhpModule = $neardBins->getPhp()->getApacheModule($version);
        
        if (!file_exists($apacheConf) || !file_exists($neardConf)) {
            Util::logError('Neard config files not found for ' . $this->getName() . ' ' . $version);
            if ($showWindow) {
                $neardWinbinder->messageBoxError(
                    sprintf($neardLang->getValue(Lang::NEARD_CONF_NOT_FOUND_ERROR), $this->getName() . ' ' . $version),
                    $boxTitle
                );
            }
            return false;
        }
        
        $neardConfRaw = parse_ini_file($neardConf);
        if ($neardConfRaw === false || !isset($neardConfRaw[self::ROOT_CFG_VERSION]) || $neardConfRaw[self::ROOT_CFG_VERSION] != $version) {
            Util::logError('Neard config file malformed for ' . $this->getName() . ' ' . $version);
            if ($showWindow) {
                $neardWinbinder->messageBoxError(
                    sprintf($neardLang->getValue(Lang::NEARD_CONF_MALFORMED_ERROR), $this->getName() . ' ' . $version),
                    $boxTitle
                );
            }
            return false;
        }
        
        if ($tsDll === false || $apachePhpModule === false) {
            Util::logDebug($this->getName() . ' ' . $version . ' does not seem to be compatible with PHP ' . $neardBins->getPhp()->getVersion());
            if ($showWindow) {
                $neardWinbinder->messageBoxError(
                    sprintf($neardLang->getValue(Lang::APACHE_INCPT), $version, $neardBins->getPhp()->getVersion()),
                    $boxTitle
                );
            }
            return false;
        }
        
        // bootstrap
        Util::replaceDefine($neardCore->getBootstrapFilePath(), 'CURRENT_APACHE_VERSION', $version);
    
        // neard.conf
        $this->setVersion($version);
        
        // httpd.conf
        Util::replaceInFile($apacheConf, array(
            '/^PHPIniDir\s.*/' => 'PHPIniDir "' . $neardBins->getPhp()->getCurrentPath() . '"',
            '/^#?LoadFile\s.*php.ts\.dll.*/' => (!file_exists($neardBins->getPhp()->getCurrentPath() . '/' . $tsDll) ? '#' : '') . 'LoadFile "' . $neardBins->getPhp()->getCurrentPath() . '/' . $tsDll . '"',
            '/^LoadModule\sphp._module\s.*/' => 'LoadModule ' . $apachePhpModuleName . ' "' . $apachePhpModule . '"',
        ));
    }
    
    public function getModules()
    {
        $fromFolder = $this->getModulesFromFolder();
        $fromConf = $this->getModulesFromConf();
        $result = array_merge($fromFolder, $fromConf);
        ksort($result);
        return $result;
    }
    
    public function getModulesFromConf()
    {
        $result = array();
    
        $confContent = file($this->getConf());
        foreach ($confContent as $row) {
            $modMatch = array();
            if (preg_match('/^(#)?LoadModule\s*([a-z0-9_-]+)\s*"?(.*)"?/i', $row, $modMatch)) {
                $name = $modMatch[2];
                $path = $modMatch[3];
                if (!Util::startWith($name, 'php')) {
                    if ($modMatch[1] == '#') {
                        $result[$name] = ActionSwitchApacheModule::SWITCH_OFF;
                    } else {
                        $result[$name] = ActionSwitchApacheModule::SWITCH_ON;
                    }
                }
            }
        }
    
        ksort($result);
        return $result;
    }
    
    public function getModulesLoaded()
    {
        $result = array();
        foreach ($this->getModulesFromConf() as $name => $status) {
            if ($status == ActionSwitchApacheModule::SWITCH_ON) {
                $result[] = $name;
            }
        }
        return $result;
    }
    
    public function getModulesFromFolder()
    {
        $result = array();
        
        if ($handle = opendir($this->getModulesPath())) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && Util::startWith($file, 'mod_') && (Util::endWith($file, '.so') || Util::endWith($file, '.dll'))) {
                    $name = str_replace(array('mod_', '.so', '.dll'), '', $file) . '_module';
                    $result[$name] = ActionSwitchApacheModule::SWITCH_OFF;
                }
            }
            closedir($handle);
        }
        
        ksort($result);
        return $result;
    }
    
    public function getAlias()
    {
        global $neardBs;
        $result = array();
        
        if ($handle = opendir($neardBs->getAliasPath())) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && Util::endWith($file, '.conf')) {
                    $result[] = str_replace('.conf', '', $file);
                }
            }
            closedir($handle);
        }
        
        ksort($result);
        return $result;
    }
    
    public function getVhosts()
    {
        global $neardBs;
        $result = array();
    
        if ($handle = opendir($neardBs->getVhostsPath())) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && Util::endWith($file, '.conf')) {
                    $result[] = str_replace('.conf', '', $file);
                }
            }
            closedir($handle);
        }
    
        ksort($result);
        return $result;
    }
    
    public function getVhostsUrl()
    {
        global $neardBs;
        $result = array();
        
        foreach ($this->getVhosts() as $vhost) {
            $vhostContent = file($neardBs->getVhostsPath() . '/' . $vhost . '.conf');
            foreach ($vhostContent as $vhostLine) {
                $vhostLine = trim($vhostLine);
                $enabled = !Util::startWith($vhostLine, '#');
                if (preg_match_all('/ServerName\s+(.*)/', $vhostLine, $matches)) {
                    foreach ($matches as $match) {
                        $found = isset($match[1]) ? trim($match[1]) : trim($match[0]);
                        if (filter_var('http://' . $found, FILTER_VALIDATE_URL) !== false) {
                            $result[$found] = $enabled;
                            break 2;
                        }
                    }
                }
            }
        }
        
        return $result;
    }
    
    public function getWwwDirectories()
    {
        global $neardBs;
        $result = array();
    
        if ($handle = opendir($neardBs->getWwwPath())) {
            while (false !== ($file = readdir($handle))) {
                if ($file != "." && $file != ".." && is_dir($neardBs->getWwwPath() . '/' . $file)) {
                    $result[] = $file;
                }
            }
            closedir($handle);
        }
    
        ksort($result);
        return $result;
    }
    
    public function getCmdLineOutput($cmd)
    {
        $result = array(
            'syntaxOk' => false,
            'content'  => null,
        );
        
        if (file_exists($this->getExe())) {
            $tmpResult = Batch::exec('apacheGetCmdLineOutput', '"' . $this->getExe() . '" ' . $cmd);
            if ($tmpResult !== false && is_array($tmpResult)) {
                $result['syntaxOk'] = trim($tmpResult[count($tmpResult) - 1]) == 'Syntax OK';
                if ($result['syntaxOk']) {
                    unset($tmpResult[count($tmpResult) - 1]);
                }
                $result['content'] = implode(PHP_EOL, $tmpResult);
            }
        }
        
        return $result;
    }
    
    public function getOnlineContent($version = null)
    {
        $version = $version != null ? $version : $this->getVersion();
        $result = '    # START switchOnline tag - Do not replace!' . PHP_EOL;
        
        if (Util::startWith($version, '2.4')) {
            $result .= '    Require all granted' . PHP_EOL;
        } else {
            $result .= '    Order Allow,Deny' . PHP_EOL .
                '    Allow from all' . PHP_EOL;
        }
        
        return $result . '    # END switchOnline tag - Do not replace!';
    }
    
    public function getOfflineContent($version = null)
    {
        $version = $version != null ? $version : $this->getVersion();
        $result = '    # START switchOnline tag - Do not replace!' . PHP_EOL;
    
        if (Util::startWith($version, '2.4')) {
            $result .= '    Require local' . PHP_EOL;
        } else {
            $result .= '    Order Deny,Allow' . PHP_EOL .
                '    Deny from all' . PHP_EOL .
                '    Allow from 127.0.0.1 ::1' . PHP_EOL;
        }
    
        return $result . '    # END switchOnline tag - Do not replace!';
    }
    
    public function getRequiredContent($version = null)
    {
        global $neardConfig;
        return $neardConfig->isOnline() ? $this->getOnlineContent($version) : $this->getOfflineContent($version);
    }
    
    public function getAliasContent($name, $dest)
    {
        $dest = Util::formatUnixPath($dest);
        return 'Alias /' . $name . ' "' . $dest . '"' . PHP_EOL . PHP_EOL .
            '<Directory "' . $dest . '">' . PHP_EOL .
            '    Options Indexes FollowSymLinks MultiViews' . PHP_EOL .
            '    AllowOverride all' . PHP_EOL .
            $this->getRequiredContent() . PHP_EOL .
            '</Directory>' . PHP_EOL;
    }
    
    public function getVhostContent($serverName, $documentRoot)
    {
        global $neardBs;
        
        $documentRoot = Util::formatUnixPath($documentRoot);
        return '<VirtualHost *:' . $this->getPort() . '>' . PHP_EOL .
            '    ServerAdmin webmaster@' . $serverName . PHP_EOL .
            '    DocumentRoot "' . $documentRoot . '"' . PHP_EOL .
            '    ServerName ' . $serverName . PHP_EOL .
            '    ErrorLog "' . $neardBs->getLogsPath() . '/' . $serverName . '_error.log"' . PHP_EOL .
            '    CustomLog "' . $neardBs->getLogsPath() . '/' . $serverName . '_access.log" combined' . PHP_EOL . PHP_EOL .
            '    <Directory "' . $documentRoot . '">' . PHP_EOL .
            '        Options Indexes FollowSymLinks MultiViews' . PHP_EOL .
            '        AllowOverride all' . PHP_EOL .
            $this->getRequiredContent() . PHP_EOL .
            '    </Directory>' . PHP_EOL .
            '</VirtualHost>' . PHP_EOL . PHP_EOL .
            '<VirtualHost *:' . $this->getSslPort() . '> #SSL' . PHP_EOL .
            '    DocumentRoot "' . $documentRoot . '"' . PHP_EOL .
            '    ServerName ' . $serverName . PHP_EOL .
            '    ServerAdmin webmaster@' . $serverName . PHP_EOL .
            '    ErrorLog "' . $neardBs->getLogsPath() . '/' . $serverName . '_error.log"' . PHP_EOL .
            '    TransferLog "' . $neardBs->getLogsPath() . '/' . $serverName . '_access.log"' . PHP_EOL . PHP_EOL .
            '    SSLEngine on' . PHP_EOL .
            '    SSLProtocol all -SSLv2' . PHP_EOL .
            '    SSLCipherSuite HIGH:MEDIUM:!aNULL:!MD5' . PHP_EOL .
            '    SSLCertificateFile "' . $neardBs->getSslPath() . '/' . $serverName . '.crt"' . PHP_EOL .
            '    SSLCertificateKeyFile "' . $neardBs->getSslPath() . '/' . $serverName . '.pub"' . PHP_EOL .
            '    BrowserMatch "MSIE [2-5]" nokeepalive ssl-unclean-shutdown downgrade-1.0 force-response-1.0' . PHP_EOL .
            '    CustomLog "' . $neardBs->getLogsPath() . '/' . $serverName . '_sslreq.log" "%t %h %{SSL_PROTOCOL}x %{SSL_CIPHER}x \"%r\" %b"' . PHP_EOL .PHP_EOL .
            '    <Directory "' . $documentRoot . '">' . PHP_EOL .
            '        SSLOptions +StdEnvVars' . PHP_EOL .
            '        Options Indexes FollowSymLinks MultiViews' . PHP_EOL .
            '        AllowOverride all' . PHP_EOL .
            $this->getRequiredContent() . PHP_EOL .
            '    </Directory>' . PHP_EOL .
            '</VirtualHost>' . PHP_EOL;
    }
    
    public function refreshAlias($putOnline)
    {
        global $neardBs, $neardBins, $neardHomepage;
        
        $onlineContent = $this->getOnlineContent();
        $offlineContent = $this->getOfflineContent();
        
        foreach ($this->getAlias() as $alias) {
            $aliasConf = file_get_contents($neardBs->getAliasPath() . '/' . $alias . '.conf');
            if ($putOnline) {
                $aliasConf = str_replace($offlineContent, $onlineContent, $aliasConf);
            } else {
                $aliasConf = str_replace($onlineContent, $offlineContent, $aliasConf);
            }
            file_put_contents($neardBs->getAliasPath() . '/' . $alias . '.conf', $aliasConf);
        }
        
        // Homepage
        $neardHomepage->refreshAliasContent();
    }
    
    public function refreshVhosts($putOnline)
    {
        global $neardBs, $neardBins;
        
        $onlineContent = $this->getOnlineContent();
        $offlineContent = $this->getOfflineContent();
        
        foreach ($this->getVhosts() as $vhost) {
            $vhostConf = file_get_contents($neardBs->getVhostsPath() . '/' . $vhost . '.conf');
            if ($putOnline) {
                $vhostConf = str_replace($offlineContent, $onlineContent, $vhostConf);
            } else {
                $vhostConf = str_replace($onlineContent, $offlineContent, $vhostConf);
            }
            file_put_contents($neardBs->getVhostsPath() . '/' . $vhost . '.conf', $vhostConf);
        }
    }
    
    public function existsSslCrt($domain = 'localhost')
    {
        global $neardBs;
        
        $ppkPath = $neardBs->getSslPath() . '/' . $domain . '.ppk';
        $pubPath = $neardBs->getSslPath() . '/' . $domain . '.pub';
        $crtPath = $neardBs->getSslPath() . '/' . $domain . '.crt';
        
        return is_file($ppkPath) && is_file($pubPath) && is_file($crtPath);
    }
    
    public function removeSslCrt($domain = 'localhost')
    {
        global $neardBs;
    
        $ppkPath = $neardBs->getSslPath() . '/' . $domain . '.ppk';
        $pubPath = $neardBs->getSslPath() . '/' . $domain . '.pub';
        $crtPath = $neardBs->getSslPath() . '/' . $domain . '.crt';
    
        return @unlink($ppkPath) && @unlink($pubPath) && @unlink($crtPath);
    }
    
    public function getName()
    {
        return $this->name;
    }
    
    public function getVersionList()
    {
        return Util::getVersionList($this->getRootPath());
    }

    public function getVersion()
    {
        return $this->version;
    }
    
    public function setVersion($version)
    {
        global $neardConfig;
        $neardConfig->replace(self::ROOT_CFG_VERSION, $version);
    }

    public function isLaunchStartup()
    {
        return $this->launchStartup;
    }
    
    public function setLaunchStartup($enabled)
    {
        global $neardConfig;
        $neardConfig->replace(self::ROOT_CFG_LAUNCH_STARTUP, $enabled);
    }
    
    public function getRootPath()
    {
        return $this->rootPath;
    }
    
    public function getCurrentPath()
    {
        return $this->currentPath;
    }
    
    public function getModulesPath()
    {
        return $this->modulesPath;
    }
    
    public function getSslConf()
    {
        return $this->sslConf;
    }
    
    public function getAccessLog()
    {
        return $this->accessLog;
    }
    
    public function getRewriteLog()
    {
        return $this->rewriteLog;
    }
    
    public function getErrorLog()
    {
        return $this->errorLog;
    }

    public function getExe()
    {
        return $this->exe;
    }
    
    public function getConf()
    {
        return $this->conf;
    }
    
    public function getPort()
    {
        return $this->port;
    }
    
    public function setPort($port)
    {
        return $this->replace(self::LOCAL_CFG_PORT, $port);
    }
    
    public function getSslPort()
    {
        return $this->sslPort;
    }
    
    public function setSslPort($sslPort)
    {
        return $this->replace(self::LOCAL_CFG_SSL_PORT, $sslPort);
    }
    
    public function getOpensslExe()
    {
        return $this->opensslExe;
    }
    
    public function getService()
    {
        return $this->service;
    }
    
}
