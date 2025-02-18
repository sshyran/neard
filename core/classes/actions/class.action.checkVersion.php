<?php

class ActionCheckVersion
{
    const DISPLAY_OK = 'displayOk';
    
    private $wbWindow;
    
    private $wbImage;
    private $wbLinkFull;
    private $wbBtnOk;
    
    private $currentVersion;
    private $latestVersion;
    
    public function __construct($args)
    {
        global $neardCore, $neardLang, $neardWinbinder;
        
        if (!file_exists($neardCore->getExec())) {
            Util::startLoading();
            $this->currentVersion = $neardCore->getAppVersion();
            $this->latestVersion =  Util::getLatestVersion();
            
            if ($this->latestVersion != null && version_compare($this->currentVersion, $this->latestVersion, '<')) {
                $labelFullLink = $neardLang->getValue(Lang::DOWNLOAD) . ' Neard ' . $this->latestVersion;
                $labelFullInfo = 'neard-' . $this->latestVersion . '.zip (' . Util::getRemoteFilesize(Util::getVersionUrl($this->latestVersion)) . ')';
                
                $neardWinbinder->reset();
                $this->wbWindow = $neardWinbinder->createAppWindow($neardLang->getValue(Lang::CHECK_VERSION_TITLE), 410, 120, WBC_NOTIFY, WBC_KEYDOWN | WBC_KEYUP);
                
                $neardWinbinder->createLabel($this->wbWindow, $neardLang->getValue(Lang::CHECK_VERSION_AVAILABLE_TEXT), 80, 15, 420, 120);
    
                $this->wbLinkFull = $neardWinbinder->createHyperLink($this->wbWindow, $labelFullLink, 80, 40, 210, 20, WBC_LINES | WBC_RIGHT);
                $neardWinbinder->createLabel($this->wbWindow, $labelFullInfo, 80, 57, 210, 20);
                
                $this->wbBtnOk = $neardWinbinder->createButton($this->wbWindow, $neardLang->getValue(Lang::BUTTON_OK), 310, 55);
                $this->wbImage = $neardWinbinder->drawImage($this->wbWindow, $neardCore->getResourcesPath() . '/about.bmp');
                
                Util::stopLoading();
                $neardWinbinder->setHandler($this->wbWindow, $this, 'processWindow');
                $neardWinbinder->mainLoop();
                $neardWinbinder->reset();
            } elseif (isset($args[0]) && !empty($args[0]) && $args[0] == self::DISPLAY_OK) {
                Util::stopLoading();
                $neardWinbinder->messageBoxInfo(
                    $neardLang->getValue(Lang::CHECK_VERSION_LATEST_TEXT),
                    $neardLang->getValue(Lang::CHECK_VERSION_TITLE));
            }
        }
    }
    
    public function processWindow($window, $id, $ctrl, $param1, $param2)
    {
        global $neardConfig, $neardWinbinder;
    
        switch($id) {
            case $this->wbLinkFull[WinBinder::CTRL_ID]:
                $neardWinbinder->exec($neardConfig->getBrowser(), Util::getVersionUrl($this->latestVersion));
                break;
            case IDCLOSE:
            case $this->wbBtnOk[WinBinder::CTRL_ID]:
                $neardWinbinder->destroyWindow($window);
                break;
        }
    }
    
}
