<?php

class TplAppMysql
{
    const MENU = 'mysql';
    const MENU_VERSIONS = 'mysqlVersions';
    const MENU_SERVICE = 'mysqlService';
    const MENU_DEBUG = 'mysqlDebug';
    
    const ACTION_SWITCH_VERSION = 'switchMysqlVersion';
    const ACTION_CHANGE_PORT = 'changeMysqlPort';
    const ACTION_INSTALL_SERVICE = 'installMysqlService';
    const ACTION_REMOVE_SERVICE = 'removeMysqlService';
    const ACTION_LAUNCH_STARTUP = 'launchStartupMysql';
    
    public static function process()
    {
        global $neardLang;
        
        return TplApp::getMenu($neardLang->getValue(Lang::MYSQL), self::MENU, get_called_class());
    }
    
    public static function getMenuMysql()
    {
        global $neardBins, $neardLang, $neardTools;
        
        $tplVersions = TplApp::getMenu($neardLang->getValue(Lang::VERSIONS), self::MENU_VERSIONS, get_called_class());
        $tplService = TplApp::getMenu($neardLang->getValue(Lang::SERVICE), self::MENU_SERVICE, get_called_class());
        $tplDebug = TplApp::getMenu($neardLang->getValue(Lang::DEBUG), self::MENU_DEBUG, get_called_class());
        
        return
        
            // Items
            $tplVersions[TplApp::SECTION_CALL] . PHP_EOL .
            $tplService[TplApp::SECTION_CALL] . PHP_EOL .
            $tplDebug[TplApp::SECTION_CALL] . PHP_EOL .
            TplAestan::getItemLink($neardLang->getValue(Lang::PHPMYADMIN), 'phpmyadmin/', true) . PHP_EOL .
            TplAestan::getItemLink($neardLang->getValue(Lang::ADMINER), 'adminer/?server=127.0.0.1%3A' . $neardBins->getMysql()->getPort() . '&username=', true) . PHP_EOL .
            TplAestan::getItemConsole(
                $neardLang->getValue(Lang::CONSOLE),
                TplAestan::GLYPH_CONSOLE,
                $neardTools->getConsole()->getTabTitleMysql()
            ) . PHP_EOL .
            TplAestan::getItemNotepad(basename($neardBins->getMysql()->getConf()), $neardBins->getMysql()->getConf()) . PHP_EOL .
            TplAestan::getItemNotepad($neardLang->getValue(Lang::MENU_ERROR_LOGS), $neardBins->getMysql()->getErrorLog()) . PHP_EOL . PHP_EOL .
            
            // Actions
            $tplVersions[TplApp::SECTION_CONTENT] . PHP_EOL .
            $tplService[TplApp::SECTION_CONTENT] . PHP_EOL .
            $tplDebug[TplApp::SECTION_CONTENT];
    }
    
    public static function getMenuMysqlVersions()
    {
        global $neardBins;
        $items = '';
        $actions = '';
        
        foreach ($neardBins->getMysql()->getVersionList() as $version) {
            $tplSwitchMysqlVersion = TplApp::getActionMulti(
                self::ACTION_SWITCH_VERSION, array($version),
                array($version, $version == $neardBins->getMysql()->getVersion() ? TplAestan::GLYPH_CHECK : ''),
                false, get_called_class()
            );
            
            // Item
            $items .= $tplSwitchMysqlVersion[TplApp::SECTION_CALL] . PHP_EOL;
        
            // Action
            $actions .= PHP_EOL . $tplSwitchMysqlVersion[TplApp::SECTION_CONTENT];
        }
        
        return $items . $actions;
    }
    
    public static function getActionSwitchMysqlVersion($version)
    {
        global $neardBs, $neardCore, $neardBins;
    
        return TplService::getActionDelete(BinMysql::SERVICE_NAME) . PHP_EOL .
            TplAestan::getActionServicesClose() . PHP_EOL .
            TplApp::getActionRun(Action::SWITCH_VERSION, array($neardBins->getMysql()->getName(), $version)) . PHP_EOL .
            TplService::getActionCreate(BinMysql::SERVICE_NAME) . PHP_EOL .
            TplService::getActionStart(BinMysql::SERVICE_NAME) . PHP_EOL .
            TplApp::getActionExec() . PHP_EOL;
    }
    
    public static function getMenuMysqlService()
    {
        global $neardLang, $neardBins;
        
        $tplChangePort = TplApp::getActionMulti(
            self::ACTION_CHANGE_PORT, null,
            array($neardLang->getValue(Lang::MENU_CHANGE_PORT), TplAestan::GLYPH_NETWORK),
            false, get_called_class()
        );
        
        $isLaunchStartup = $neardBins->getMysql()->isLaunchStartup();
        $tplLaunchStartup = TplApp::getActionMulti(
            self::ACTION_LAUNCH_STARTUP, array($isLaunchStartup ? Config::DISABLED : Config::ENABLED),
            array($neardLang->getValue(Lang::MENU_LAUNCH_STARTUP_SERVICE), $isLaunchStartup ? TplAestan::GLYPH_CHECK : ''),
            false, get_called_class()
        );
        
        $result = TplAestan::getItemActionServiceStart($neardBins->getMysql()->getService()->getName()) . PHP_EOL .
            TplAestan::getItemActionServiceStop($neardBins->getMysql()->getService()->getName()) . PHP_EOL .
            TplAestan::getItemActionServiceRestart($neardBins->getMysql()->getService()->getName()) . PHP_EOL .
            TplAestan::getItemSeparator() . PHP_EOL .
            TplApp::getActionRun(
                Action::CHECK_PORT, array($neardBins->getMysql()->getName(), $neardBins->getMysql()->getPort()),
                array(sprintf($neardLang->getValue(Lang::MENU_CHECK_PORT), $neardBins->getMysql()->getPort()), TplAestan::GLYPH_LIGHT)
            ) . PHP_EOL .
            $tplChangePort[TplApp::SECTION_CALL] . PHP_EOL .
            $tplLaunchStartup[TplApp::SECTION_CALL] . PHP_EOL;
        
        $isInstalled = $neardBins->getMysql()->getService()->isInstalled();
        if (!$isInstalled) {
            $tplInstallService = TplApp::getActionMulti(
                self::ACTION_INSTALL_SERVICE, null,
                array($neardLang->getValue(Lang::MENU_INSTALL_SERVICE), TplAestan::GLYPH_SERVICE_INSTALL),
                $isInstalled, get_called_class()
            );
        
            $result .= $tplInstallService[TplApp::SECTION_CALL] . PHP_EOL . PHP_EOL .
            $tplInstallService[TplApp::SECTION_CONTENT] . PHP_EOL;
        } else {
            $tplRemoveService = TplApp::getActionMulti(
                self::ACTION_REMOVE_SERVICE, null,
                array($neardLang->getValue(Lang::MENU_REMOVE_SERVICE), TplAestan::GLYPH_SERVICE_REMOVE),
                !$isInstalled, get_called_class()
            );
        
            $result .= $tplRemoveService[TplApp::SECTION_CALL] . PHP_EOL . PHP_EOL .
            $tplRemoveService[TplApp::SECTION_CONTENT] . PHP_EOL;
        }
        
        $result .= $tplChangePort[TplApp::SECTION_CONTENT] . PHP_EOL .
            $tplLaunchStartup[TplApp::SECTION_CONTENT] . PHP_EOL;
    
        return $result;
    }
    
    public static function getMenuMysqlDebug()
    {
        global $neardLang;
    
        return TplApp::getActionRun(
            Action::DEBUG_MYSQL, array(BinMysql::CMD_VERSION),
            array($neardLang->getValue(Lang::DEBUG_MYSQL_VERSION), TplAestan::GLYPH_DEBUG)
        ) . PHP_EOL .
        TplApp::getActionRun(
            Action::DEBUG_MYSQL, array(BinMysql::CMD_VARIABLES),
            array($neardLang->getValue(Lang::DEBUG_MYSQL_VARIABLES), TplAestan::GLYPH_DEBUG)
        ) . PHP_EOL .
        TplApp::getActionRun(
            Action::DEBUG_MYSQL, array(BinMysql::CMD_SYNTAX_CHECK),
            array($neardLang->getValue(Lang::DEBUG_MYSQL_SYNTAX_CHECK), TplAestan::GLYPH_DEBUG)
        ) . PHP_EOL;
    }
    
    public static function getActionChangeMysqlPort()
    {
        global $neardLang, $neardBins;
    
        return TplApp::getActionRun(Action::CHANGE_PORT, array($neardBins->getMysql()->getName())) . PHP_EOL .
            TplAppReload::getActionReload();
    }
    
    public static function getActionInstallMysqlService()
    {
        return TplApp::getActionRun(Action::SERVICE, array(BinMysql::SERVICE_NAME, ActionService::INSTALL)) . PHP_EOL .
            TplAppReload::getActionReload();
    }
    
    public static function getActionRemoveMysqlService()
    {
        return TplApp::getActionRun(Action::SERVICE, array(BinMysql::SERVICE_NAME, ActionService::REMOVE)) . PHP_EOL .
            TplAppReload::getActionReload();
    }
    
    public static function getActionLaunchStartupMysql($launchStartup)
    {
        global $neardBins;
    
        return TplApp::getActionRun(Action::LAUNCH_STARTUP_SERVICE, array($neardBins->getMysql()->getName(), $launchStartup)) . PHP_EOL .
            TplAppReload::getActionReload();
    }
    
}
