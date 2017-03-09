<?php
/**
 *    Copyright (C) 2017 Frank Wall
 *    Copyright (C) 2015 Deciso B.V.
 *
 *    All rights reserved.
 *
 *    Redistribution and use in source and binary forms, with or without
 *    modification, are permitted provided that the following conditions are met:
 *
 *    1. Redistributions of source code must retain the above copyright notice,
 *       this list of conditions and the following disclaimer.
 *
 *    2. Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *
 *    THIS SOFTWARE IS PROVIDED ``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES,
 *    INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY
 *    AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE
 *    AUTHOR BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY,
 *    OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 *    SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 *    INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 *    CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 *    ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 *    POSSIBILITY OF SUCH DAMAGE.
 *
 */
namespace OPNsense\AcmeClient\Api;

use \OPNsense\Base\ApiControllerBase;
use \OPNsense\AcmeClient\AcmeClient;
use \OPNsense\Core\Backend;
use \OPNsense\Core\Config;
use \OPNsense\Base\UIModelGrid;

/**
 * Class CertificatesController
 * @package OPNsense\AcmeClient
 */
class CertificatesController extends ApiControllerBase
{
    /**
     * Validate and save model after update or insertion.
     * Use the reference node and tag to rename validation output for a specific
     * node to a new offset, which makes it easier to reference specific uuids
     * without having to use them in the frontend descriptions.
     * @param $mdl model reference
     * @param $node reference node, to use as relative offset
     * @param $reference reference for validation output, used to rename the validation output keys
     * @return array result / validation output
     */
    private function save($mdl, $node = null, $reference = null)
    {
        $result = array("result"=>"failed","validations" => array());
        // perform validation
        $valMsgs = $mdl->performValidation();
        foreach ($valMsgs as $field => $msg) {
            // replace absolute path to attribute for relative one at uuid.
            if ($node != null) {
                $fieldnm = str_replace($node->__reference, $reference, $msg->getField());
                $result["validations"][$fieldnm] = $msg->getMessage();
            } else {
                $result["validations"][$msg->getField()] = $msg->getMessage();
            }
        }

        // serialize model to config and save when there are no validation errors
        if (count($result['validations']) == 0) {
            // save config if validated correctly
            $mdl->serializeToConfig();

            Config::getInstance()->save();
            $result = array("result" => "saved");
        }

        return $result;
    }

    /**
     * retrieve certificate settings or return defaults
     * @param $uuid item unique id
     * @return array
     */
    public function getAction($uuid = null)
    {
        $mdlAcme = new AcmeClient();
        if ($uuid != null) {
            $node = $mdlAcme->getNodeByReference('certificates.certificate.'.$uuid);
            if ($node != null) {
                // return node
                return array("certificate" => $node->getNodes());
            }
        } else {
            // generate new node, but don't save to disc
            $node = $mdlAcme->certificates->certificate->add();
            return array("certificate" => $node->getNodes());
        }
        return array();
    }

    /**
     * update certificate with given properties
     * @param $uuid item unique id
     * @return array
     */
    public function setAction($uuid)
    {
        if ($this->request->isPost() && $this->request->hasPost("certificate")) {
            $mdlAcme = new AcmeClient();
            if ($uuid != null) {
                $node = $mdlAcme->getNodeByReference('certificates.certificate.'.$uuid);
                if ($node != null) {
                    $node->setNodes($this->request->getPost("certificate"));
                    return $this->save($mdlAcme, $node, "certificate");
                }
            }
        }
        return array("result"=>"failed");
    }

    /**
     * add new certificate and set with attributes from post
     * @return array
     */
    public function addAction()
    {
        $result = array("result"=>"failed");
        if ($this->request->isPost() && $this->request->hasPost("certificate")) {
            $mdlAcme = new AcmeClient();
            $node = $mdlAcme->certificates->certificate->Add();
            $node->setNodes($this->request->getPost("certificate"));
            return $this->save($mdlAcme, $node, "certificate");
        }
        return $result;
    }

    /**
     * delete certificate by uuid
     * @param $uuid item unique id
     * @return array status
     */
    public function delAction($uuid)
    {
        $result = array("result"=>"failed");
        if ($this->request->isPost()) {
            $mdlAcme = new AcmeClient();
            if ($uuid != null) {
                if ($mdlAcme->certificates->certificate->del($uuid)) {
                    // if item is removed, serialize to config and save
                    $mdlAcme->serializeToConfig();
                    Config::getInstance()->save();
                    $result['result'] = 'deleted';
                } else {
                    $result['result'] = 'not found';
                }
            }
        }
        return $result;
    }

    /**
     * toggle certificate by uuid (enable/disable)
     * @param $uuid item unique id
     * @param $enabled desired state enabled(1)/disabled(0), leave empty for toggle
     * @return array status
     */
    public function toggleAction($uuid, $enabled = null)
    {

        $result = array("result" => "failed");
        if ($this->request->isPost()) {
            $mdlAcme = new AcmeClient();
            if ($uuid != null) {
                $node = $mdlAcme->getNodeByReference('certificates.certificate.' . $uuid);
                if ($node != null) {
                    if ($enabled == "0" || $enabled == "1") {
                        $node->enabled = (string)$enabled;
                    } elseif ((string)$node->enabled == "1") {
                        $node->enabled = "0";
                    } else {
                        $node->enabled = "1";
                    }
                    $result['result'] = $node->enabled;
                    // if item has toggled, serialize to config and save
                    $mdlAcme->serializeToConfig();
                    Config::getInstance()->save();
                }
            }
        }
        return $result;
    }

    /**
     * search certificates
     * @return array
     */
    public function searchAction()
    {
        $this->sessionClose();
        $mdlAcme = new AcmeClient();
        $grid = new UIModelGrid($mdlAcme->certificates->certificate);
        return $grid->fetchBindRequest(
            $this->request,
            array("enabled", "name", "altNames", "description", "lastUpdate", "statusCode", "statusLastUpdate"),
            "name"
        );
    }

    /**
     * sign certificate by uuid
     * @param $uuid item unique id
     * @return array status
     */
    public function signAction($uuid)
    {
        $result = array("result"=>"failed");
        if ($this->request->isPost()) {
            $mdlAcme = new AcmeClient();

            if ($uuid != null) {
                $node = $mdlAcme->getNodeByReference('certificates.certificate.' . $uuid);
                if ($node != null) {
                    $cert_id = $node->id;
                    $backend = new Backend();
                    $response = $backend->configdRun("acmeclient sign-cert {$cert_id}");
                    return array("response" => $response);
                }
            }
        }
        return $result;
    }

    /**
     * revoke certificate by uuid
     * @param $uuid item unique id
     * @return array status
     */
    public function revokeAction($uuid)
    {
        $result = array("result"=>"failed");
        if ($this->request->isPost()) {
            $mdlAcme = new AcmeClient();

            if ($uuid != null) {
                $node = $mdlAcme->getNodeByReference('certificates.certificate.' . $uuid);
                if ($node != null) {
                    $cert_id = $node->id;
                    $backend = new Backend();
                    $response = $backend->configdRun("acmeclient revoke-cert {$cert_id}");
                    return array("response" => $response);
                }
            }
        }
        return $result;
    }
}
