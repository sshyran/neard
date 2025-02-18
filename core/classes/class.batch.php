<?php

class Batch
{
    const END_PROCESS_STR = 'FINISHED!';
    const CATCH_OUTPUT_FALSE = 'neardCatchOutputFalse';
    
    public function __construct()
    {
        
    }
    
    private static function writeLog($log)
    {
        global $neardBs;
        Util::logDebug($log, $neardBs->getBatchLogFilePath());
    }
    
    public static function findExeByPid($pid)
    {
        $result = self::exec('findExeByPid', 'TASKLIST /FO CSV /NH /FI "PID eq ' . $pid . '"', 5);
        if ($result !== false) {
            $expResult = explode('","', $result[0]);
            if (is_array($expResult) && count($expResult) > 2 && isset($expResult[0]) && !empty($expResult[0])) {
                return substr($expResult[0], 1);
            }
        }
        
        return false;
    }
    
    public static function getProcessUsingPort($port)
    {
        $result = self::exec('getProcessUsingPort', 'NETSTAT -aon', 4);
        if ($result !== false) {
            foreach ($result as $row) {
                if (!Util::startWith($row, 'TCP')) {
                    continue;
                }
                $rowExp = explode(' ', preg_replace('/\s+/', ' ', $row));
                if (count($rowExp) == 5 && Util::endWith($rowExp[1], ':' . $port) && $rowExp[3] == 'LISTENING') {
                    $pid = intval($rowExp[4]);
                    $exe = self::findExeByPid($pid);
                    if ($exe !== false) {
                        return $exe . ' (' . $pid . ')';
                    }
                    return $pid;
                }
            }
        }
    
        return null;
    }
    
    public static function exitApp($restart = false)
    {
        global $neardBs, $neardCore;
    
        $content = 'PING 1.1.1.1 -n 1 -w 2000 > nul' . PHP_EOL;
        $content .= '"' . $neardBs->getExeFilePath() . '" -quit -id={neard}' . PHP_EOL;
        if ($restart) {
            $basename = 'restartApp';
            Util::logInfo('Restart App');
            $content .= '"' . $neardCore->getPhpCliSilentExe() . '" "' . Core::BOOTSTRAP_FILE . '" "' . Action::RESTART . '"' . PHP_EOL;
        } else {
            $basename = 'exitApp';
            Util::logInfo('Exit App');
        }
        
        Win32Ps::killBins();
        self::execStandalone($basename, $content);
    }
    
    public static function restartApp()
    {
        self::exitApp(true);
    }
    
    public static function getPearVersion()
    {
        global $neardBins;
        
        $result = self::exec('getPearVersion', 'CMD /C "' . $neardBins->getPhp()->getPearExe() . '" -V', 5);
        if (is_array($result)) {
            foreach ($result as $row) {
                if (Util::startWith($row, 'PEAR Version:')) {
                    $expResult = explode(' ', $row);
                    if (count($expResult) == 3) {
                        return trim($expResult[2]);
                    }
                }
            }
        }
        
        return null;
    }
    
    public static function getSvnVersion()
    {
        global $neardTools;
    
        $result = self::exec('getSvnVersion', 'CMD /C "' . $neardTools->getSvn()->getExe() . '" --version', 5);
        if (!empty($result) && is_array($result)) {
            $rebuildResult = array();
            foreach ($result as $row) {
                $rebuildResult[] = Util::cp1252ToUtf8($row);
            }
            $result = $rebuildResult;
        }
    
        return $result;
    }
    
    public static function refreshEnvVars()
    {
        global $neardBs, $neardTools;
        self::execStandalone('refreshEnvVars', '"' . $neardTools->getSetenv()->getExe() . '" -a ' . Registry::APP_PATH_REG_ENTRY . ' "' . Util::formatWindowsPath($neardBs->getRootPath()) . '"');
    }
    
    public static function genSslCertificate($name, $destPath = null)
    {
        global $neardBs, $neardBins;
        $destPath = empty($destPath) ? $neardBs->getSslPath() : $destPath;
        
        $subject = '"/C=FR/O=neard/CN=' . $name . '"';
        $password = 'pass:neard';
        $ppkPath = '"' . $destPath . '/' . $name . '.ppk"';
        $pubPath = '"' . $destPath . '/' . $name . '.pub"';
        $crtPath = '"' . $destPath . '/' . $name . '.crt"';
        $exe = '"' . $neardBins->getApache()->getOpensslExe() . '"';
        $conf = '"' . $neardBs->getSslConfPath() . '"';
        
        self::exec('genSslKey', $exe . ' genrsa -des3 -passout ' . $password . ' -out ' . $ppkPath . ' 2048 -noout -config ' . $conf);
        self::exec('genSslPub', $exe . ' rsa -in ' . $ppkPath . ' -passin ' . $password . ' -out ' . $pubPath);
        self::exec('genSslCrt', $exe . ' req -x509 -nodes -sha1 -new -key ' . $pubPath . ' -out ' . $crtPath . ' -passin ' . $password . ' -subj ' . $subject . ' -config ' . $conf);
        
        $result = self::exec('checkCertificate', '@ECHO ON' . PHP_EOL . 'IF EXIST ' . $pubPath . ' IF EXIST ' . $crtPath . ' ECHO OK');
        return isset($result[0]) && $result[0] == 'OK';
    }
    
    public static function installFilezillaService()
    {
        global $neardBins;
        
        self::exec('installFilezillaService', '"' . $neardBins->getFilezilla()->getExe() . '" /install', true, false);
        return $neardBins->getFilezilla()->getService()->isInstalled();
    }
    
    public static function uninstallFilezillaService()
    {
        global $neardBins;
    
        self::exec('uninstallFilezillaService', '"' . $neardBins->getFilezilla()->getExe() . '" /uninstall', true, false);
        return !$neardBins->getFilezilla()->getService()->isInstalled();
    }
    
    public static function execStandalone($basename, $content, $silent = true)
    {
        return self::exec($basename, $content, false, false, true, $silent);
    }
    
    public static function exec($basename, $content, $timeout = true, $catchOutput = true, $standalone = false, $silent = true, $rebuild = true)
    {
        global $neardConfig, $neardCore, $neardWinbinder;
        $result = false;
    
        $resultFile = self::getTmpFile('.tmp', $basename);
        $scriptPath = self::getTmpFile('.bat', $basename);
        $checkFile = self::getTmpFile('.tmp', $basename);
    
        // Redirect output
        if ($catchOutput) {
            $content .= '> "' . $resultFile . '"' . (!Util::endWith($content, '2') ? ' 2>&1' : '');
        }
        
        // Header
        $header = '@ECHO OFF' . PHP_EOL . PHP_EOL;
        
        // Footer
        $footer = PHP_EOL . (!$standalone ? PHP_EOL . 'ECHO ' . self::END_PROCESS_STR . ' > "' . $checkFile . '"' : '');
        
        // Process
        file_put_contents($scriptPath, $header . $content . $footer);
        $neardWinbinder->exec($scriptPath, null, $silent);
        
        if (!$standalone) {
            $timeout = is_numeric($timeout) ? $timeout : ($timeout === true ? $neardConfig->getScriptsTimeout() : false);
            $maxtime = time() + $timeout;
            $noTimeout = $timeout === false;
            while ($result === false || empty($result)) {
                if (file_exists($checkFile)) {
                    $check = file($checkFile);
                    if (!empty($check) && trim($check[0]) == self::END_PROCESS_STR) {
                        if ($catchOutput && file_exists($resultFile)) {
                            $result = file($resultFile);
                        } else {
                            $result = self::CATCH_OUTPUT_FALSE;
                        }
                    }
                }
                if ($maxtime < time() && !$noTimeout) {
                    break;
                }
            }
        }
        
        self::writeLog('Exec:');
        self::writeLog('-> basename: ' . $basename);
        self::writeLog('-> content: ' . str_replace(PHP_EOL, ' \\\\ ', $content));
        self::writeLog('-> checkFile: ' . $checkFile);
        self::writeLog('-> resultFile: ' . $resultFile);
        self::writeLog('-> scriptPath: ' . $scriptPath);
        
        if ($result !== false && !empty($result) && is_array($result)) {
            if ($rebuild) {
                $rebuildResult = array();
                foreach ($result as $row) {
                    $row = trim($row);
                    if (!empty($row)) {
                        $rebuildResult[] = $row;
                    }
                }
                $result = $rebuildResult;
            }
            self::writeLog('-> result: ' . substr(implode(' \\\\ ', $result), 0, 2048));
        } else {
            self::writeLog('-> result: N/A');
        }
        
        return $result;
    }
    
    private static function getTmpFile($ext, $customName = null)
    {
        global $neardCore;
        return Util::formatWindowsPath($neardCore->getTmpPath() . '/' . (!empty($customName) ? $customName . '-' : '') . Util::random() . $ext);
    }
    
}
