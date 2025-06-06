<?php
// Copyright 1999-2023. Plesk International GmbH. All rights reserved.
require_once('api-common/cu.php');
require_once('api-common/cuApp.php');

cu::initCLI();

class InstallationInfo extends cuApp
{
    protected $_needToCheckPsaConfigured = false;

    public function __construct()
    {
        parent::__construct();

        $this->allowed_commands = [
            [
                CU_OPT_LONG => 'save',
                CU_OPT_PARAM => false,
                CU_OPT_DESC => 'Save info about Plesk installation',
            ],
        ];

        $this->allowed_options = [
            [
                CU_OPT_LONG   => 'mode',
                CU_OPT_PARAM  => true,
            ],
            [
                CU_OPT_LONG   => 'preset',
                CU_OPT_PARAM  => true,
            ],
            [
                CU_OPT_LONG   => 'arguments',
                CU_OPT_PARAM  => true,
            ],
        ];
    }

    protected function _saveCommand($mode, $preset, $arguments)
    {
        put_param('installation_mode', $this->getMode($mode));
        put_param('installation_preset', $preset);
        put_param('installation_arguments', $arguments);
        put_param('installation_finish', time());
    }

    private function getMode($mode)
    {
        if (!$this->os->isUnix()) {
            return $mode;
        }
        if (empty(getenv('PLESK_ONE_CLICK_INSTALLER'))) {
            return $mode;
        }
        return 'ONE_CLICK';
    }
}

$app = new InstallationInfo();
$app->runFromCli();
