<?php

namespace Neuron\Addon\Evidence\UTOnline\Services;

use DateTime;
use Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue;
use Neuron\Application\Framework\Entity;
use Neuron\Application\Myevidence\Beanstalkd;
use Neuron\Generic\Result;
use Neuron\Order as OrderModel;
use Neuron\Addon\Evidence\UTOnline\Entity\STO;
use Neuron\Addon\Evidence\UTOnline\Maping\Orders as OrderMap;
use Neuron\Addon\Evidence\UTOnline\Maping\Attributes as AttributesMap;
use Neuron\Addon\Evidence\UTOnline\Maping\Details as DetailsMap;
use Neuron\Addon\Evidence\UTOnline\Entity\OrderUjiPetik;
use Neuron\Addon\Evidence\UTOnline\Entity\Order as OrderAddon;
use Neuron\Addon\Evidence\UTOnline\Entity\OrderData;
use Neuron\Addon\Evidence\UTOnline\Services\AlistaService;
use Neuron\Addon\Evidence\UTOnline\Entity\UTUjiPetik;
use Neuron\Addon\Evidence\UTOnline\Entity\OrderSymptomInvalid;
use Zend\Http\Request;
use Zend\Http\Client;
use Zend\Stdlib\Parameters;



class OrderService extends \Neuron\Addon\Evidence\UTOnline\Controller
{

    public function saveApproved($order, $data)
    {
        $naf = Entity::get();
        $session = $naf->session()->get();

        $entity = new OrderModel\Entity(
            \Neuron\Order\Entity\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        if (isset($session['active_role']['code']) && ($session['active_role']['code'] == OrderMap::APPROVED_ROLE || $session['active_role']['code'] == OrderMap::APPROVED_ROLE_EBIS)) {
            $data['order_status_id'] = OrderMap::APPROVED_CODE;
            $verifBy = AttributesMap::TIPE_VERIFICATION;
        } elseif (isset($session['active_role']['code']) && ($session['active_role']['code'] == OrderMap::INBOX_ROLE || $session['active_role']['code'] == OrderMap::INBOX_ROLE_EBIS)) {
            $verifBy = AttributesMap::TIPE_VERIFICATION;
            $data['order_status_id'] = OrderMap::NEED_APROVE;
        }

        unset($data['flow']);
        $flow = array();

        if (isset($session['active_role']['code']) && ($session['active_role']['code'] == OrderMap::APPROVED_ROLE || $session['active_role']['code'] == OrderMap::APPROVED_ROLE_EBIS)) {
            $flow = [
                'order_flow_status_id' => 2,
                'order_flow_type_id'  => 2,
                'order_flow_task_code'  => 'approval',
                'remark_in'  => 'Tolong di periksa ya.',
                'remark_out'  => 'Sudah saya periksa.',
                'assigned_to_id'  => [
                    4 => 'approval'
                ],
            ];

            $data['xs14'] = '0';
            $data['xs17'] = null;
            $data['xs18'] = OrderMap::QC_STATUS_DEFAULT;
        }

        if (isset($session['active_role']['code']) && ($session['active_role']['code'] == OrderMap::INBOX_ROLE || $session['active_role']['code'] == OrderMap::INBOX_ROLE_EBIS)) {
            $flow = [
                'order_flow_status_id' => 2,
                'order_flow_type_id'  => 2,
                'order_flow_task_code'  => 'approval',
                'remark_in'  => 'Tolong di periksa ya.',
                'remark_out'  => 'Sudah saya periksa.',
                'assigned_to_id'  => [
                    3 => 'approval'
                ],
            ];
        }

        $resultQC = $this->getQCValidate($order['order_id']);

        if (isset($resultQC['data']['qcApproverId'])
            && $resultQC['data']['qcApproverId']
            && $order['qcApproveBy']
            && $resultQC['data']['isComWa']
            && ($session['active_role']['code'] == OrderMap::APPROVED_ROLE || $session['active_role']['code'] == OrderMap::APPROVED_ROLE_EBIS)) {
            $flow = [
                'order_flow_status_id' => 2,
                'order_flow_type_id'  => 2,
                'order_flow_task_code'  => 'approval',
                'remark_in'  => 'Tolong di periksa ya.',
                'remark_out'  => 'Sudah saya periksa.',
                'assigned_to_id'  => [
                    $resultQC['data']['qcApproverId'] => 'approval'
                ],
            ];

            $data['order_status_id'] = OrderMap::VALID_QC2_MANDATORY;
        }

        if (!empty($flow)) {
            $data['flow'] = $flow;
        }
        $data['xs14'] = '0';

        $result = $entity->update($data);

        //Queue ke Beanstalkd
        if($data['order_status_id'] == OrderMap::APPROVED_CODE){

            $beanstalkdQueue = new \Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue(
                \Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue\Storage::factory(
                    $naf->getDb(),
                    $naf->getController()->getConfig()
                )
            );

            $tube = (isset($dataOrder->data[OrderMap::FRESH_ORDER]) && $dataOrder->data[OrderMap::FRESH_ORDER]) ? Beanstalkd::TUBE_REWORK_T1 : Beanstalkd::TUBE_FRESH_T1;
            $beansData = [
                'order_id'  => $result->data,
                'tube'      => $tube,
                'status'    => BeanstalkdQueue::STATUS_UNREAD,
            ];
            
            if ($beanstalk = new Beanstalkd($this)) {
                $beanResult = $beanstalk->putJob($tube, $result->data);

                if(!$beanResult){
                    $beanstalkdQueue->insert($beansData);
                }

            } else {
                $beanstalkdQueue->insert($beansData);
            }
        }

        if ($result->code == $result::CODE_SUCCESS) {
            //Insert Verifikasi
            $dataVerifs = $data['verifs'] ?? null;
            $user = $naf->user(true);

            if ($dataVerifs) {
                //Save Uji Petik
                $this->saveEvidenceUjiPetik($order, $dataVerifs);

                //Update Attribute
                $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

                $dataOrder = $entity->loadEx($data, \Neuron\Order\Entity\Storage::LOAD_DEEP);

                $dataVerifs = AttributesMap::setMapData($dataVerifs, $verifBy);

                $dataAttributeVerification = array();

                if (isset($dataOrder->data['attributes']) && $dataOrder->data['attributes']) {
                    $dataAttributeVerification = AttributesMap::attributeDetails($order['attributes']);
                    $dataAttributeVerification = $dataAttributeVerification[AttributesMap::TIPE_ATTRIBUTES[$verifBy]];
                }

                foreach ($dataVerifs as $key => $attributes) {
                    $find = AttributesMap::searchBy($dataAttributeVerification, $attributes['xn1'], $data['order_id'], $attributes['order_attribute_type_id']);

                    if ($find) {
                        $attributes['order_attribute_id'] = $find['orderAttributeId'];
                        $save = \Neuron\Order\Attribute\Storage::SAVEBY_ID;
                        $attributes['xn4'] = $user->id;
                    } else {
                        $save = \Neuron\Order\Attribute\Storage::SAVE_INSERT;
                        if (!isset($attributes['xn3'])) {
                            $attributes['xn3'] = $user->id;
                        }
                    }

                    $attributes['xn5']      = $attributes['xn2'];
                    $attributes['order_id'] = $data['order_id'];

                    $attribute->saveEx($attributes, $save);
                }

                //Save Existing
                $dataOrderAttribute = OrderMap::getMapData($dataOrder->data);
                $this->saveUpdateAttributesEvidence(array('attributes' => $dataVerifs), $dataOrderAttribute, OrderMap::APPROVED_ACTION);
            }

            // save date sybmit

            $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());

            $OrderAddon->updateTimelineOrder($data['order_id'],'STARTSUBMIT');

            //Save Activity
            $dataActivity = [
                'order_id'  => $data['order_id'],
                'xn2'       => $user->id,
                'xs1'       => 'MILESTONE',
                'xs2'       => 'APPROVE ' . $session['active_role']['name'],
                'xs3'       => $user->code,
                'xs4'       => 'APPROVED',
                'xs5'       => json_encode($data),
                'xs6'       => $session['active_role']['code'],
                'xs7'       => $session['active_role']['name']
            ];

            $this->saveOrderActivities($dataActivity);
        }

        return $result;
    }

    public function saveReturnBai($data)
    {
        $result = new \Neuron\Generic\Result();
        $naf = Entity::get();
        $session = $naf->session()->get();
        $dataPost = $data;

        $entity = new OrderModel\Entity(
            \Neuron\Order\Entity\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        $load = $entity->loadEx($data, \Neuron\Order\Entity\Storage::LOAD_DEEP);
       
        $order = OrderMap::getMapData($load->data);

        $materialTidakSesuai    = $data['details'][0]['xs10'] ?? 0;
        $fotoTidakSesuai        = $data['details'][0]['xs11'] ?? 0;
        $baTidakSesuai          = $data['details'][0]['xs12'] ?? 0;
        $hasilUkurKosong        = $data['details'][0]['xs13'] ?? 0;
        $tanggalTidakSesuai     = $data['details'][0]['xs18'] ?? 0;
        $koordinatTidakSesuai   = $data['details'][0]['xs19'] ?? 0;
        $keteranganReturn       = $data['details'][0]['xs14'] ?? 0;

        $dataNotValid = [
            'materialTidakSesuai' => $materialTidakSesuai,
            'fotoTidakSesuai'     => $fotoTidakSesuai,
            'baTidakSesuai'       => $baTidakSesuai,
            'hasilUkurKosong'     => $hasilUkurKosong,
            'tanggalTidakSesuai'  => $tanggalTidakSesuai,
            'koordinatTidakSesuai' => $koordinatTidakSesuai,
        ];
        
        //Get Detail Order
        $order['attr_details'] = AttributesMap::attributeDetails($order['attributes']);
        $totalEvidence = count($order['attr_details']['evidenceTeknisi']);
        $totalVerifEvidence = count($data['verifs']);

        if ($totalEvidence <> $totalVerifEvidence && $fotoTidakSesuai == 1) {
            $result->code = 1;
            $result->info = 'Tidak dapat diproses. Silahkan lakukan approval pada Foto Evidence.';
            $result->data = null;
            return $result;
        }

        if (strlen($keteranganReturn) < 5) {
            $result->code = 1;
            $result->info = 'Keterangan harus diisi setidaknya 5 karakter.';
            $result->data = null;
            return $result;
        }

        //Check If Has Tidak Sesuai
        if (isset($data['verifs']) && $data['verifs'] && $fotoTidakSesuai == 0) {
            $utils = new \Neuron\Application\Myevidence\Utils;
            if ($utils->findByKey($data['verifs'], 'xn2', 2) !== FALSE) { // jika tidak ada data yang tidak sesuai maka invalid
                $result->code = 1;
                $result->info = 'Foto Eviden ada yang tidak sesuai/tidak valid, silahkan centang Foto Kurang lengkap.';
                $result->data = null;
                return $result;
            } else {
                $fotoTidakSesuai    = 0;
            }
        }

        if (!$fotoTidakSesuai) {
            unset($data['verifs']);
        }

        if (!isset($data['verifs']) && $fotoTidakSesuai == 1) {
            if (empty($data['verifs'])) {
                $result->code = 1;
                $result->info = 'Tidak dapat diproses. Silahkan lakukan approval pada Foto Evidence.';
                $result->data = null;
                return $result;
            } else {
                $data['details'][0]['xs11'] = 1; // AUTOSET Jika ada foto tidak sesuai 
            }
        }

        //Set status order jika role ditentukan
        $active_role_code = $session['active_role']['code'];
        $callSaveNotValid = false;
        if (($active_role_code == OrderMap::QC_ROLE || $active_role_code == OrderMap::QC_ROLE_EBIS)
            || ($active_role_code == OrderMap::AGENT_QC2 || $active_role_code == OrderMap::AGENT_QC2_EBIS)) {
            $data['order_status_id'] = 17;
            $verifBy = AttributesMap::TIPE_VERIF_QC2;

            $callSaveNotValid = true;
        } else {
            $data['order_status_id'] = 14;
            $verifBy = AttributesMap::TIPE_VERIF_ASO;
        }

        //Assign TO TL
        $data['flow']['remark_in']  = 'Tolong diperbaiki';
        $data['flow']['assigned_to_id']     = [
            2   => 'return'
        ];

        //Get Datek
        $dataOrder = $entity->loadEx($data['order_id'], \Neuron\Order\Entity\Storage::LOAD_DEEP);
        if ($result->code != 0 && !$dataOrder && !$dataOrder['order_id'] && !$dataOrder['order_code']) {
            $result->code = 1;
            $result->info = 'Order ID Tidak sesuai.';

            return $result;
        }

        $dataOrder = OrderMap::getMapData($dataOrder->data);
        $datek = DetailsMap::collectdetails($dataOrder['details'], DetailsMap::DATEK, false);

        $dataDetail = [
            'order_id'              => $data['order_id'],
            'order_detail_type_id'  => 4,
            'order_detail_id'       => $datek['orderDetailId'] ?? null,
            'xs10'                  => $materialTidakSesuai,
            'xs11'                  => $fotoTidakSesuai,
            'xs12'                  => $baTidakSesuai,
            'xs13'                  => $hasilUkurKosong,
            'xs14'                  => $keteranganReturn,
            'xs18'                  => $tanggalTidakSesuai,
            'xs19'                  => $koordinatTidakSesuai
        ];

        $dataVerifs = $data['verifs'] ?? null;

        unset($data['details']);
        if($dataVerifs){
            $data['attributes'] = AttributesMap::setMapData($dataVerifs, $verifBy);
            unset($data['verifs']);
            
            $this->saveEvidenceUjiPetik($dataOrder, $dataVerifs);
        }

        $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());

        $isPilotingCreateTicket = $OrderAddon->isPilotingCreateTicket($order);

        if ($isPilotingCreateTicket) {
                
            $ujiPetik = new UTUjiPetik(UTUjiPetik\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
            $ujiPetikListInfracare = $ujiPetik->loadList(false, UTUjiPetik\Storage::LISTBY_INFRACARE);
            $dataInfracare = $ujiPetikListInfracare->data;
            $dataTicketInfracare = DetailsMap::collectdetails($dataOrder['details'], DetailsMap::TIMELINE_ORDER, false);
               
            $dataUjiPetikInfracare = DetailsMap::collectUjiPetikInfracareReopen($dataVerifs,$dataInfracare,$dataTicketInfracare);

            if (isset($dataUjiPetikInfracare) && $dataUjiPetikInfracare) {

                $resultReopenOrder = "";
                $resultFailedReopenTIcket = [];

                foreach ($dataUjiPetikInfracare as $valueTicketInfracare) {

                    if ($valueTicketInfracare['statusTicket'] == 2 || $valueTicketInfracare['statusTicket'] == 4 ) {

                        $reopenTicket = $this->reopenTicket([
                            'ticketid' => $valueTicketInfracare['ticketId'],
                            'order_id' => $dataOrder['order_id']
                        ]);

                        $statusTicket = $reopenTicket->code != 0 ? 4 : 1;
                        $messageTicket = $reopenTicket->code != 0 ? "Gagal Reopen ticket" : "Berhasil Reopen Ticket";
            
                        $dataUpdate['order_detail_id'] =  $dataTicketInfracare['orderDetailId'];
            
                        if ($valueTicketInfracare['codeLabel'] == 'ODP03') {
                            $dataUpdate['xn1'] =  $statusTicket;
                        }else if ($valueTicketInfracare['codeLabel'] == 'ODP06' ) {
                            $dataUpdate['xn2'] =   $statusTicket;
                        }else if ($valueTicketInfracare['codeLabel'] == 'ODP13') {
                            $dataUpdate['xn3'] =  $statusTicket;
                        }else if($valueTicketInfracare['codeLabel'] == 'ODP09'){
                            $dataUpdate['xn5'] =  $statusTicket;
                        }
            
                        $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
                        $detailService->saveDetails($dataUpdate);

                        $resultReopenOrder .= 'Tiket '.$valueTicketInfracare.' '.$messageTicket.PHP_EOL;

                        if ($statusTicket == 4) {
                            array_push($resultFailedReopenTIcket,$valueTicketInfracare['ticketId']);
                        }
                    }
                        
                }

                if (isset($resultFailedReopenTIcket) && $resultFailedReopenTIcket) {

                    $result->code = 1;
                    $result->info = $resultReopenOrder;
                    
                }else{

                    $result->code = 0;
                    $result->info = $resultReopenOrder;

                }
    
                return $result;
                
            }
        }

        $user = $naf->user(true);
        $data['update_user_id'] = $user->id;
        $result = $entity->update($data);

        if ($result->code == $result::CODE_SUCCESS) {
            $detail = new \Neuron\Order\Detail(\Neuron\Order\Detail\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
            $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

            $detail->saveEx($dataDetail, \Neuron\Order\Detail\Storage::SAVEBY_ID);
        
            //Save Update Attributes
            $this->saveUpdateAttributes($data, $dataOrder, $verifBy);
            $this->saveUpdateAttributesEvidence($data, $dataOrder, OrderMap::REJECT_ACTION);

            $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());
            
            $OrderAddon->updateTimelineOrder($dataOrder['order_id'],'STARTSUBMIT');

            //Save Activity
            $dataActivity = [
                'order_id'  => $dataOrder['order_id'],
                'xn2'       => $user->id,
                'xs1'       => 'MILESTONE',
                'xs2'       => 'REJECT ' . $session['active_role']['name'],
                'xs3'       => $user->code,
                'xs4'       => $keteranganReturn,
                'xs5'       => json_encode($dataPost),
                'xs6'       => $session['active_role']['code'],
                'xs7'       => $session['active_role']['name']
            ];

            $this->saveOrderActivities($dataActivity);

            //Execution Update Flag
            $this->saveDetailFlagReject($data);
            
            // $getHistoryASO = $OrderAddon->getHistoryApprover($order, OrderMap::ASO_ROLE_ID);
            // print_r($getHistoryASO);die;

            $user = new \Neuron\Addon\Evidence\UTOnline\Entity\User(
                \Neuron\Addon\Evidence\UTOnline\Entity\User\Storage::factory($naf->getDb(),
                $naf->base()->getModuleConfig())
            );

            // $getTelegramId = $user->getTelegramID(['sto' => $dataOrder['sto']]);
            // print_r($getTelegramId);die;

            //ambil label foto tidak valid

            $orderActivity = new OrderData(OrderData\Storage::factory($naf->getDb(), $naf->base()->getModuleConfig()));

            $getHistoryEvidence = $orderActivity->getHistoryEvidence(['order_id' => $dataOrder['order_id'],'activity' => $session['active_role']['name'],'action' =>OrderMap::REJECT_ACTION]);
            $uniqueData = [];
            foreach ($getHistoryEvidence as $valueEvidence) {
                $uniqueData[$valueEvidence->xs6]  = $valueEvidence;
            }

            $dataLabelfotoNotvalid = array_values($uniqueData);

            //Send Telegram to leader TA

            if(($active_role_code == OrderMap::APPROVED_ROLE || $active_role_code == OrderMap::APPROVED_ROLE_EBIS)
                || ($active_role_code == OrderMap::AGENT_QC2 || $active_role_code == OrderMap::AGENT_QC2_EBIS)
                || ($active_role_code == OrderMap::QC_ROLE || $active_role_code == OrderMap::QC_ROLE_EBIS)){ // reject aso dan qc2
               
                $dataSendTelegram = [
                    'user_id' => $user->id,
                    'role' => $session['active_role']['name'],
                    'order_id' => $dataOrder['order_id'],
                    'order_wo' => $dataOrder['order_code'],
                    'leader' => $dataOrder['leader'],
                    'scId'  => $dataOrder['scId'],
                    'tglWO' => $dataOrder['tglWo'],
                ];

                $isPiloting = $OrderAddon->isPiloting($dataOrder);
                // cek piloting
                if ($isPiloting) {
                    $dataSendTelegram['keterangan'] = $this->formatKeteranganTelegramPiloting($dataVerifs,$keteranganReturn,$fotoTidakSesuai,$dataLabelfotoNotvalid,$dataOrder,$post['agentTlTa']); 
                }else{
                    $dataSendTelegram['keterangan'] = $this->formatKeteranganTelegramNonPiloting($dataNotValid,$keteranganReturn,$dataLabelfotoNotvalid,$dataOrder); 
                    
                }
                
                $this->sendTelegram($dataSendTelegram);
            }

            // Send Telegram to user ASO 

            if (($active_role_code == OrderMap::AGENT_QC2 || $active_role_code == OrderMap::AGENT_QC2_EBIS)
                || ($active_role_code == OrderMap::QC_ROLE && $active_role_code == OrderMap::QC_ROLE_EBIS)) {
                

                $dataSendTelegram = [
                    'user_id' => $user->id,
                    'role' => $session['active_role']['name'],
                    'order_id' => $dataOrder['order_id'],
                    'order_wo' => $dataOrder['order_code'],
                    'sto' => $dataOrder['sto'],
                    'keterangan' => $this->formatKeteranganTelegramNonPiloting($dataNotValid,$keteranganReturn,$dataLabelfotoNotvalid,$dataOrder),
                    'scId'  => $dataOrder['scId'],
                    'tglWO' => $dataOrder['tglWo'],
                ];


                $this->sendTelegram($dataSendTelegram);

            }

        }

        if ($callSaveNotValid) { // Panggil untuk menyimpan data not valid untuk qc2
            $qcNotes = $keteranganReturn;
            $this->saveNotValid($dataOrder, $qcNotes, $dataVerifs);
        }

        return $result;
    }

    public function saveEvidenceUjiPetik($order, $dataVerifs)
    {
        $naf = Entity::get();

        $dataInput = array();
        foreach ($dataVerifs as $key => $verif) {
            if (isset($verif['ujiPetikData']) && $verif['ujiPetikData']) {
                foreach ($verif['ujiPetikData'] as $ujipetik) {
                    $dataInput[] = [
                        'order_id'              => $order['order_id'],
                        'order_attribute_id'    => $verif['xn1'],
                        'code'                  => $ujipetik['id'],
                        'status_id'             => $ujipetik['status'],
                        'label'                 => $ujipetik['label']
                    ];
                }
            }
        }

        $ujiPetik = new OrderUjiPetik(OrderUjiPetik\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        if (!empty($dataInput)) {
            //Cleansing Exist Data
            $ujiPetik->delete($order['order_id'], OrderUjiPetik\Storage::DELETEBY_ORDERID);

            foreach ($dataInput as $ujp) {
                $saveInsert = $ujiPetik->saveEx($ujp, \Neuron\Order\Detail\Storage::SAVE_INSERT);
            }
        }

        return true;
    }

    public function saveReturnMyTech($data)
    {
        $result = new \Neuron\Generic\Result();
        $naf = Entity::get();
        $user = $naf->user(true);
        $dataRequest = $data;

        $session = $naf->session()->get();
        $entity = new OrderModel\Entity(
            \Neuron\Order\Entity\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());

        $materialTidakSesuai    = $data['details'][0]['xs10'] ?? 0;
        $fotoTidakSesuai        = $data['details'][0]['xs11'] ?? 0;
        $baTidakSesuai          = $data['details'][0]['xs12'] ?? 0;
        $hasilUkurKosong        = $data['details'][0]['xs13'] ?? 0;
        $tanggalTidakSesuai     = $data['details'][0]['xs18'] ?? 0;
        $koordinatTidakSesuai   = $data['details'][0]['xs19'] ?? 0;
        $keteranganReturn       = $data['details'][0]['xs14'] ?? 0;
        $gantiTeknisi           = $data['details'][0]['xs21'] ?? 0;
        $labercode              = $data['labercode'] ?? 0;

        if (strlen($keteranganReturn) < 5) {
            $result->code = 1;
            $result->info = 'Keterangan harus diisi setidaknya 5 karakter.';
            $result->data = null;
            return $result;
        }

        if (isset($data['verifs']) && $data['verifs'] && $fotoTidakSesuai == 0) {
            $utils = new \Neuron\Application\Myevidence\Utils;
            if ($utils->findByKey($data['verifs'], 'xn2', 2) !== FALSE) { // jika tidak ada data yang tidak sesuai maka invalid
                $result->code = 1;
                $result->info = 'Foto Eviden ada yang tidak sesuai/tidak valid, silahkan centang Foto Kurang lengkap.';
                $result->data = null;
                return $result;
            } else {
                $fotoTidakSesuai    = 0;
            }
        }

        if (!$fotoTidakSesuai) {
            /*$result->code = 1;
            $result->info = 'Untuk sementara (BA tidak sesuai dan Hasil Ukur Under spec / Kosong), tidak dapat dikirim ke MyTech';
            $result->data = null;
            return $result; */
            unset($data['verifs']);
        }

        if (!isset($data['verifs']) && $fotoTidakSesuai == 1) {
            if (empty($data['verifs'])) {
                $result->code = 1;
                $result->info = 'Tidak dapat diproses. Silahkan lakukan approval pada Foto Evidence.';
                $result->data = null;
                return $result;
            } else {
                $data['details'][0]['xs11'] = 1; // AUTOSET Jika ada foto tidak sesuai 
            }
        }

        $entity = new OrderModel\Entity(
            \Neuron\Order\Entity\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        $load = $entity->loadEx($data, \Neuron\Order\Entity\Storage::LOAD_DEEP);
        $order = OrderMap::getMapData($load->data);
        // print_r($order['details']);die;
        // $dataOrderDetails['details'] = DetailsMap::detailOrder($order['details']);
        //Get Detail Order
        $order['attr_details'] = AttributesMap::attributeDetails($order['attributes']);
        $totalEvidence = count($order['attr_details']['evidenceTeknisi'] ?? []);
        $totalVerifEvidence = count($data['verifs'] ?? []);

        if ($totalEvidence <> $totalVerifEvidence && $fotoTidakSesuai == 1) {
            $result->code = 1;
            $result->info = 'Tidak dapat diproses. Silahkan lakukan approval pada ' . $totalEvidence . ' Foto Eviden.';
            $result->data = null;
            return $result;
        }

        //Check If Has Tidak Sesuai
        if (isset($data['verifs']) && $data['verifs'] && $fotoTidakSesuai == 1) {
            $utils = new \Neuron\Application\Myevidence\Utils;
            if($utils->findByKey($data['verifs'], 'xn2', 2) === FALSE){ // jika tidak ada data yang tidak sesuai maka invalid
                $result->code = 1;
                $result->info = 'Foto Eviden sesuai semua, tidak bisa direturn.';
                $result->data = null;
                return $result;
            }
        }

        //Load Order
        // $assigments = $this->getAssigment();

        /*if($assigments){
            $approval = array();
            foreach ($assigments as $key => $assign) {
                $approval[$assign['code']] = 'return';
            }
            
            $data['flow']['assigned_to_id']     = $approval;
        }else{
            if($session['active_role']['code'] <> OrderMap::AGENT_QC2){
                $result->code = 99;
                $result->info = 'Tidak dapat diproses. Target Assigment/Approval tidak ditemukan.';
                $result->data = null;
                return $result; 
            }
        }*/

        $data['flow']['remark_in']  = 'Return Mytech';
        $data['flow']['assigned_to_id']     = [
            1   => 'return'
        ];

        $data['order_status_id']    = OrderMap::REOPEN_MYTECH;

        //Get Datek
        $dataOrder = $entity->loadEx($data['order_id'], \Neuron\Order\Entity\Storage::LOAD_DEEP);
        if ($result->code != 0 && !$dataOrder && !$dataOrder['order_id'] && !$dataOrder['order_code']) {
            $result->code = 1;
            $result->info = 'Order ID Tidak sesuai.';

            return $result;
        }

        $dataOrder = OrderMap::getMapData($dataOrder->data);
        //print_r($dataOrder);die;
        $datek = DetailsMap::collectdetails($dataOrder['details'], DetailsMap::DATEK, false);
        $dataTicket =  DetailsMap::collectdetails($dataOrder['details'], DetailsMap::TIMELINE_ORDER, false);

        $dataDetail = [
            'order_id'              => $data['order_id'],
            'order_detail_type_id'  => 4,
            'order_detail_id'       => $datek['orderDetailId'] ?? null,
            'xs10'                  => $materialTidakSesuai,
            'xs11'                  => $fotoTidakSesuai,
            'xs12'                  => $baTidakSesuai,
            'xs13'                  => $hasilUkurKosong,
            'xs14'                  => $keteranganReturn,
            'xs18'                  => $tanggalTidakSesuai,
            'xs19'                  => $koordinatTidakSesuai,
            'xs21'                  => $gantiTeknisi,
        ];

        $dataVerifs = $data['verifs'] ?? null;
        if ($dataVerifs && $fotoTidakSesuai == 1) {
            $data['attributes'] = AttributesMap::setMapData($dataVerifs, AttributesMap::TIPE_VERIFICATION);
            unset($data['verifs']);
            $isPilotingCreateTicket = $OrderAddon->isPilotingCreateTicket($order);
            if ($isPilotingCreateTicket) {
                if ($dataTicket['statusTicketOdpGendong'] == 1 || $dataTicket['statusTicketOdpKotor'] == 1 ||  $dataTicket['statusTicketOdpTidakAda'] ==1 ) {

                    $ujiPetik = new UTUjiPetik(UTUjiPetik\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
                    $ujiPetikList = $ujiPetik->loadList(false, UTUjiPetik\Storage::LISTBY_INFRACARE);
                    $dataInfracare = $ujiPetikList->data;
    
                    foreach ($dataVerifs as $key => $value ) {
                       foreach ($value['ujiPetikData'] as $keyA => $valueB) {
                            if ($valueB['status'] == 2 && array_search($valueB['label'], array_column($dataInfracare, 'name')) !== false) {
                                unset($dataVerifs[$key]['ujiPetikData'][$keyA]);
                            }
                       }
                    }
                }
            }
           
        }

        unset($data['details']); //Hapus detail kiriman ajax
        if ($gantiTeknisi == 1) {
            $dataOrder['laborcode'] = $labercode;
        }
        //Hit API Mytech Reopen
        // print_r($dataOrder);die;

        if ($materialTidakSesuai == 1) {

            $alista = new AlistaService;
            /*sync material alista*/
            $syncAlista = $alista->syncMaterialAlista(['order_id' => $dataOrder['order_id'],'labor_code' => $dataOrder['laborCode']]);
            /*sync delete alista*/
            $deleteAlista = $alista->deleteMaterialAlista(['order_id' => $dataOrder['order_id'],'wo' => $dataOrder['order_code']]);
            
            if($deleteAlista->code == 0){
            
                $dataMaterialAlista = AttributesMap::collect($dataOrder['attributes'], AttributesMap::TIPE_ATTRIBUTE_ALISTA);

                $dataActivity = [
                    'order_id'  => $dataOrder['order_id'],
                    'xs1'       => 'BACKUP',
                    'xs2'       => 'DATA MATERIAL ALISTA',
                    'xs5'       => json_encode($dataMaterialAlista),
                   
                ];
                $this->saveOrderActivities($dataActivity);

                //Hapus data alista
                foreach ($dataMaterialAlista as $itemMaterial) {
                    $attribute->delete($itemMaterial['orderAttributeId']);
                }
    
            }
            # code...
        }
        
        $hitAPI = $this->sendAPIReturnMytech($dataOrder);


        if (isset($hitAPI->code) && $hitAPI->code > 0) {
            $result->code = 1;
            $result->info = 'Gagal update return ke mytech.';

            return $result;
        }

        if ($dataVerifs) {
            $this->saveEvidenceUjiPetik($dataOrder, $dataVerifs);
        }

        $detail = new \Neuron\Order\Detail(\Neuron\Order\Detail\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

        $detail->saveEx($dataDetail, \Neuron\Order\Detail\Storage::SAVEBY_ID);

        if (isset($data['attributes']) && $data['attributes'] && $fotoTidakSesuai == 1) {
            $this->saveUpdateAttributes($data, $dataOrder, AttributesMap::TIPE_VERIFICATION);
            $this->saveUpdateAttributesEvidence($data, $dataOrder, OrderMap::RETURN_MYTECH_ACTION);
        }

        if (($session['active_role']['code'] == OrderMap::QC_ROLE || $session['active_role']['code'] == OrderMap::QC_ROLE_EBIS)
            || ($session['active_role']['code'] == OrderMap::AGENT_QC2 || $session['active_role']['code'] == OrderMap::AGENT_QC2_EBIS)) {
            //Proses di Service
            $services = new \Neuron\Addon\Evidence\UTOnline\Services\QC2Service;
            $dataPost = [
                'qcStatus'  => 2,
                'qcNotes'   => $dataDetail['xs14'],
                'verifs'    => $dataVerifs
            ];
            $saveValidNotValid = $services->saveValidNotValid($dataOrder, $dataPost);
        }


        $OrderAddon->updateTimelineOrder($dataOrder['order_id'],'SUBMIT');

        //Save Activity
        $dataActivity = [
            'order_id'  => $dataOrder['order_id'],
            'xn2'       => $user->id,
            'xs1'       => 'MILESTONE',
            'xs2'       => 'RETURN MYTECH',
            'xs3'       => $user->code,
            'xs4'       => $keteranganReturn,
            'xs5'       => json_encode($dataRequest),
            'xs6'       => $session['active_role']['code'],
            'xs7'       => $session['active_role']['name']
        ];

        $this->saveOrderActivities($dataActivity);

        /*
         * Update xs14 After Log Activities
         */
        $data['xs14'] = '0';
        $result = $entity->update($data);
        return $result;
    }

    public function sendAPIReturnMytech($dataOrder)
    {
        $datek = DetailsMap::collectdetails($dataOrder['details'], DetailsMap::DATEK, false);
        //Hit API Mytech Reopen
        $curlRequest            = AttributesMap::curlRequest($dataOrder, $datek);

        return $curlRequest;
    }

    public function getUjiPetik($evidence)
    {
        $naf = Entity::get();

        $ujiPetik = new OrderUjiPetik(OrderUjiPetik\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        $checkUjp = $ujiPetik->loadList($evidence['orderAttributeId'], OrderUjiPetik\Storage::LOADBY_ATTR_ID);

        return $checkUjp->data ?? null;
    }

    public function saveOrderActivities($dataActivity)
    {
        $naf = Entity::get();

        $activity = new \Neuron\Order\Activity(
            \Neuron\Order\Activity\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        if ($activity->saveEx($dataActivity)) {
            return true;
        }

        return false;
    }

    public function saveDetailFlagReject($dataOrder)
    {
        $naf = Entity::get();
        $user = $naf->user(true);
        $session = $naf->session()->get();

        //Load Detail QC
        $storage = \Neuron\Order\Detail\Storage::factory($naf->getDb(), $naf->getController()->getConfig());

        $detail = new \Neuron\Order\Detail($storage);

        $resultQC = $this->getQCValidate($dataOrder['order_id']);

        if (!$resultQC['status']) {
            $detail->id = 0;
            $update = false;
            $detail->xd1        = $storage->now();
            $mode = \Neuron\Order\Detail\Storage::SAVE_INSERT;
        } else {
            $detail->load($resultQC['data']['id']);
            $detail->order_detail_id = $resultQC['data']['id'];
            $update = true;
            $detail->update_dtm = $storage->now();
            $mode = \Neuron\Order\Detail\Storage::SAVEBY_ID;
        }

        $session = $naf->session()->get();

        $detail->order_id   = $dataOrder['order_id'];
        $detail->typeId     = DetailsMap::QC_STATUS;
        $detail->xs4        = $session['active_role']['code'];
        $detail->xs5        = $session['code'];
        $detail->xs6        = $session['name'];
        $detail->xs7        = $session['active_role']['access_role_id'];
        $detail->xd2        = $storage->now();

        return $detail->save($mode);
    }

    public function saveNotValid($dataOrder, $qcNotes, $dataVerifs)
    {
        $naf = Entity::get();
        $session = $naf->session()->get();
        $result = new \Neuron\Generic\Result();

        if ($dataVerifs) {
            $dataOrder['attributes'] = AttributesMap::setMapData($dataVerifs, AttributesMap::TIPE_VERIF_QC2);
        }

        //Load Detail QC
        $storage = \Neuron\Order\Detail\Storage::factory($naf->getDb(), $naf->getController()->getConfig());

        $detail = new \Neuron\Order\Detail($storage);

        $resultQC = $this->getQCValidate($dataOrder['order_id']);

        if (!$resultQC['status']) {
            $detail->id = 0;
            $update = false;
            $detail->xd1        = $storage->now();
            $mode = \Neuron\Order\Detail\Storage::SAVE_INSERT;
        } else {
            $detail->load($resultQC['data']['id']);
            $detail->order_detail_id = $resultQC['data']['id'];
            $update = true;
            $detail->update_dtm = $storage->now();
            $mode = \Neuron\Order\Detail\Storage::SAVEBY_ID;
        }

        $detail->order_id   = $dataOrder['order_id'];
        $detail->typeId     = DetailsMap::QC_STATUS;
        $detail->xn1        = OrderMap::QC_STATUS_INVALID;
        $detail->xs1        = 'Tidak Valid';
        $detail->xs2        = $qcNotes;
        $detail->xs3        = $session['code'];
        if($detail->xs4 == null){
            $detail->xs4    = '0';
        }

        if ($detail->save($mode)) {
            $result->code = 0;
            $result->info = 'Berhasil menyimpan data.';

            //Trigger Last Update Status Valid Order
            $entity = new OrderModel\Entity(
                \Neuron\Order\Entity\Storage::factory($naf->getDb(), $naf->getController()->getConfig())
            );

            $data = [
                'order_id'  => $dataOrder['order_id'],
                'xs17'      => $session['code'],
                'xs18'      => OrderMap::QC_STATUS_INVALID,
                'xs19'      => 'Tidak Valid',
                'xs20'      => $qcNotes,
                'xd3'       => $storage->now(),
            ];

            $entity->update($data);

            return $result;
        }

        $result->code = 1;
        $result->info = 'Gagal menyimpan data.';

        return $result;
    }

    public function saveUpdateAttributes($data, $dataOrder, $verifBy)
    {
        $naf = Entity::get();
        $user = $naf->user(true);

        if (isset($data['attributes']) && $data['attributes']) {

            $dataAttributeVerification = array();
            if (isset($dataOrder['attributes']) && $dataOrder['attributes']) {
                $dataAttributeVerification = AttributesMap::attributeDetails($dataOrder['attributes']);
                $dataAttributeVerification = $dataAttributeVerification[AttributesMap::TIPE_ATTRIBUTES[$verifBy]];
            }
            $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

            //Update Attribute
            foreach ($data['attributes'] as $key => $attributes) {

                $find = AttributesMap::searchBy($dataAttributeVerification, $attributes['xn1'], $data['order_id'], $attributes['order_attribute_type_id']);

                if ($find) {
                    $attributes['order_attribute_id'] = $find['orderAttributeId'];
                    $save = \Neuron\Order\Attribute\Storage::SAVEBY_ID;
                    $attributes['xn4'] = $data['update_user_id'];
                } else {
                    $save = \Neuron\Order\Attribute\Storage::SAVE_INSERT;
                    $attributes['xn3'] = $user->id;
                }

                $attributes['order_id'] = $data['order_id'];
                $attributes['xn5']      = $attributes['xn2'];

                $attribute->saveEx($attributes, $save);
            }
        }

        return true;
    }

    public function saveUpdateAttributesEvidence($data, $dataOrder, $action)
    {

        $naf = Entity::get();
        $user = $naf->user(true);
        $session = $naf->session()->get();

        if (isset($data['attributes']) && $data['attributes']) {
            $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

            //Update Attribute
            foreach ($data['attributes'] as $key => $attributes) {

                $save = \Neuron\Order\Attribute\Storage::SAVEBY_ID;

                $dataAttribute = [
                    'order_attribute_id'        => $attributes['xn1'],
                    'order_id'                  => $dataOrder['order_id'],
                    'xn4'                       => $user->id,
                    'xn2'                       => $attributes['xn2'],
                    'xn5'                       => $attributes['xn2'],
                    'xs2'                       => $attributes['xs2'],
                    'order_attribute_type_id'   => AttributesMap::TIPE_EVIDENCE_TEKNISI,
                ];

                $attribute->saveEx($dataAttribute, $save);

                if (strlen($attributes['xs2']) >= 10) {

                    $find = AttributesMap::searchByEvidence($dataOrder['attributes'], $attributes['xn1'], $dataOrder['order_id'], AttributesMap::TIPE_EVIDENCE_TEKNISI);

                    if ($find) {

                        $dataActivity = [
                            'order_id'  => $dataOrder['order_id'],
                            'xn2'       => $user->id,
                            'xn3'       => $session['active_role']['access_role_id'],
                            'xn4'       => $attributes['xn2'],
                            'xn5'       => $attributes['xn1'],
                            'xs1'       => 'HISTORY_EVIDENCE',
                            'xs2'       => $session['active_role']['name'],
                            'xs3'       => $user->code,
                            'xs4'       => $attributes['xs2'],
                            'xs5'       => $find['path'],
                            'xs6'       => $find['label'],
                            'xs8'       => $action,
                        ];

                        $this->saveOrderActivities($dataActivity);
                    }
                }
            }
        }

        return true;
    }

    public function getOrderIdByWonum($wonum)
    {
        $naf = Entity::get();

        $order = new OrderData(OrderData\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        $getOrderId = $order->getOrderIdByWoNUm($wonum);

        return $getOrderId ?? null;
    }

    public function saveApprovedPiloting($order, $data)
    {

        $naf = Entity::get();
        $session = $naf->session()->get();
        $result = new \Neuron\Generic\Result();
       

        $entity = new OrderModel\Entity(
            \Neuron\Order\Entity\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        $dataOrder = $entity->loadEx($data, \Neuron\Order\Entity\Storage::LOAD_DEEP);
        $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());


        if(isset($dataOrder->data[OrderMap::FRESH_ORDER]) && $dataOrder->data[OrderMap::FRESH_ORDER]){ // jika order nya rework
            if (isset($session['active_role']['code'])
                && ($session['active_role']['code'] == OrderMap::APPROVED_ROLE || $session['active_role']['code'] == OrderMap::APPROVED_ROLE_EBIS)) {
                $data['order_status_id'] = OrderMap::APPROVED_CODE;
            } else {
                $data['order_status_id'] = OrderMap::NEED_APROVE;
            }
            $verifBy = AttributesMap::TIPE_VERIFICATION;
        } else {
            $data['order_status_id'] = OrderMap::APPROVED_CODE;
            $verifBy = AttributesMap::TIPE_VERIFICATION;
        }

        unset($data['flow']);
        $flow = array();

        /*if(empty($dataOrder->data[OrderMap::FRESH_ORDER]) ){
            $flow = [
                'order_flow_status_id' => 2,
                'order_flow_type_id'  => 2,
                'order_flow_task_code'  => 'approval',
                'remark_in'  => 'Tolong di periksa ya.',
                'remark_out'  => 'Sudah saya periksa.',
                'assigned_to_id'  => [
                    4 => 'approval'
                ],
            ];

            $data['xs14'] = '0';
            $data['xs17'] = null;
            $data['xs18'] = OrderMap::QC_STATUS_DEFAULT;
        }*/
       
        if(isset($dataOrder->data[OrderMap::FRESH_ORDER]) && $dataOrder->data[OrderMap::FRESH_ORDER]){ // jika order nya rework

            if (isset($session['active_role']['code']) && ($session['active_role']['code'] == OrderMap::APPROVED_ROLE || $session['active_role']['code'] == OrderMap::APPROVED_ROLE_EBIS)) {
                
                $isPilotingCreateTicket = $OrderAddon->isPilotingCreateTicket($order);

                if ($isPilotingCreateTicket ) {

                $ujiPetik = new UTUjiPetik(UTUjiPetik\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
                $ujiPetikListInfracare = $ujiPetik->loadList(false, UTUjiPetik\Storage::LISTBY_INFRACARE);
                $dataInfracare = $ujiPetikListInfracare->data;
                $dataOrdersTicket = OrderMap::getMapData($dataOrder->data);
                $dataTicketInfracare = DetailsMap::collectdetails($dataOrdersTicket['details'], DetailsMap::TIMELINE_ORDER, false);
                $dataUjiPetikInfracare = DetailsMap::collectUjiPetikInfracareReopen($data['verifs'],$dataInfracare,$dataTicketInfracare);

                if (isset($dataUjiPetikInfracare) && $dataUjiPetikInfracare) {

                    $resultReopenOrder = "";
                    $resultFailedReopenTIcket = [];

                    foreach ($dataUjiPetikInfracare as $valueTicketInfracare) {

                        $reopenTicket = $this->reopenTicket([
                            'ticketid' => $valueTicketInfracare['ticketId'],
                            'order_id' => $dataOrdersTicket['order_id']
                        ]);

                        $statusTicket = $reopenTicket->code != 0 ? 4 : 1;
                        $messageTicket = $reopenTicket->code != 0 ? "Gagal Reopen ticket" : "Berhasil Reopen Ticket";
            
                        $dataUpdate['order_detail_id'] =  $dataTicketInfracare['orderDetailId'];
            
                        if ($valueTicketInfracare['codeLabel'] == 'ODP03') {
                            $dataUpdate['xn1'] =  $statusTicket;
                        }else if ($valueTicketInfracare['codeLabel'] == 'ODP06' ) {
                            $dataUpdate['xn2'] =   $statusTicket;
                        }else if ($valueTicketInfracare['codeLabel'] == 'ODP13') {
                            $dataUpdate['xn3'] =  $statusTicket;
                        }else if($valueTicketInfracare['codeLabel'] == 'ODP09'){
                            $dataUpdate['xn5'] =  $statusTicket;
                        }
            
                        $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
                        $detailService->saveDetails($dataUpdate);

                        $resultReopenOrder .= 'Tiket '.$valueTicketInfracare['ticketId'].' '.$messageTicket.PHP_EOL;

                        if ($statusTicket == 4) {
                            array_push($resultFailedReopenTIcket,$valueTicketInfracare['ticketId']);
                        }
                                
                    }

                    if (isset($resultFailedReopenTIcket) && $resultFailedReopenTIcket) {

                        $result->code = 1;
                        $result->info = $resultReopenOrder;
                        
                    }else{

                        $result->code = 0;
                        $result->info = $resultReopenOrder;

                    }
                    
        
                    return $result;

                }else{

                    $flow = [
                        'order_flow_status_id' => 2,
                        'order_flow_type_id'  => 2,
                        'order_flow_task_code'  => 'approval',
                        'remark_in'  => 'Tolong di periksa ya.',
                        'remark_out'  => 'Sudah saya periksa.',
                        'assigned_to_id'  => [
                            4 => 'approval'
                        ],
                    ];
                    
                    $data['xs17'] = null;
                    $data['xs18'] = OrderMap::QC_STATUS_DEFAULT;
                }

                   
                }
                
                
            }else{
                $flow = [
                    'order_flow_status_id' => 2,
                    'order_flow_type_id'  => 2,
                    'order_flow_task_code'  => 'approval',
                    'remark_in'  => 'Tolong di periksa ya.',
                    'remark_out'  => 'Sudah saya periksa.',
                    'assigned_to_id'  => [
                        3 => 'approval'
                    ],
                ];
            }
            
        }else{
            $flow = [
                'order_flow_status_id' => 2,
                'order_flow_type_id'  => 2,
                'order_flow_task_code'  => 'approval',
                'remark_in'  => 'Tolong di periksa ya.',
                'remark_out'  => 'Sudah saya periksa.',
                'assigned_to_id'  => [
                    4 => 'approval'
                ],
            ];

            $data['xs17'] = null;
            $data['xs18'] = OrderMap::QC_STATUS_DEFAULT;
        }

        $resultQC = $this->getQCValidate($order['order_id']);

        if (isset($resultQC['data']['qcApproverId'])
            && $resultQC['data']['qcApproverId']
            && $order['qcApproveBy']
            && $resultQC['data']['isComWa']
            && ($session['active_role']['code'] == OrderMap::APPROVED_ROLE || $session['active_role']['code'] == OrderMap::APPROVED_ROLE_EBIS)) {
            $flow = [
                'order_flow_status_id' => 2,
                'order_flow_type_id'  => 2,
                'order_flow_task_code'  => 'approval',
                'remark_in'  => 'Tolong di periksa ya.',
                'remark_out'  => 'Sudah saya periksa.',
                'assigned_to_id'  => [
                    $resultQC['data']['qcApproverId'] => 'approval'
                ],
            ];

            $data['order_status_id'] = OrderMap::VALID_QC2_MANDATORY;
        }

        //Insert Verifikasi
        $dataVerifs = $data['verifs'] ?? null;
        $user = $naf->user(true);

        if ($dataVerifs) {
            //Save Uji Petik
            $this->saveEvidenceUjiPetik($order, $dataVerifs);

            //Update Attribute
            $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

            $dataVerifs = AttributesMap::setMapData($dataVerifs, $verifBy);

            $dataAttributeVerification = array();

            if (isset($dataOrder->data['attributes']) && $dataOrder->data['attributes']) {
                $dataAttributeVerification = AttributesMap::attributeDetails($order['attributes']);
                $dataAttributeVerification = $dataAttributeVerification[AttributesMap::TIPE_ATTRIBUTES[$verifBy]];
            }

            foreach ($dataVerifs as $key => $attributes) {
                $find = AttributesMap::searchBy($dataAttributeVerification, $attributes['xn1'], $data['order_id'], $attributes['order_attribute_type_id']);

                if ($find) {
                    $attributes['order_attribute_id'] = $find['orderAttributeId'];
                    $save = \Neuron\Order\Attribute\Storage::SAVEBY_ID;
                    $attributes['xn4'] = $user->id;
                } else {
                    $save = \Neuron\Order\Attribute\Storage::SAVE_INSERT;
                    if (!isset($attributes['xn3'])) {
                        $attributes['xn3'] = $user->id;
                    }
                }

                $attributes['xn5']      = $attributes['xn2'];
                $attributes['order_id'] = $data['order_id'];

                $attribute->saveEx($attributes, $save);
            }

            //Save Existing
            $dataOrderAttribute = OrderMap::getMapData($dataOrder->data);
            $this->saveUpdateAttributesEvidence(array('attributes' => $dataVerifs), $dataOrderAttribute, OrderMap::APPROVED_ACTION);
        }

        $OrderAddon->updateTimelineOrder($data['order_id'],'STARTSUBMIT');

        //Save Activity
        $dataActivity = [
            'order_id'  => $data['order_id'],
            'xn2'       => $user->id,
            'xs1'       => 'MILESTONE',
            'xs2'       => 'APPROVE ' . $session['active_role']['name'],
            'xs3'       => $user->code,
            'xs4'       => 'APPROVED',
            'xs5'       => json_encode($data),
            'xs6'       => $session['active_role']['code'],
            'xs7'       => $session['active_role']['name']
        ];

        $this->saveOrderActivities($dataActivity);

        /*
         * Set xs14 to null after Save Order Activities
         */
        if (!empty($flow)) {
            $data['flow'] = $flow;
        }
        $data['xs14'] = '0';
       
        $result = $entity->update($data);

        //Queue ke Beanstalkd
        if($data['order_status_id'] == OrderMap::APPROVED_CODE){

            $beanstalkdQueue = new \Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue(
                \Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue\Storage::factory(
                    $naf->getDb(),
                    $naf->getController()->getConfig()
                )
            );

            $tube = (isset($dataOrder->data[OrderMap::FRESH_ORDER]) && $dataOrder->data[OrderMap::FRESH_ORDER]) ? Beanstalkd::TUBE_REWORK_T1 : Beanstalkd::TUBE_FRESH_T1;
            $beansData = [
                'order_id'  => $result->data,
                'tube'      => $tube,
                'status'    => BeanstalkdQueue::STATUS_UNREAD,
            ];

            if ($beanstalk = new Beanstalkd($this)) {
                $beanResult = $beanstalk->putJob($tube, $result->data);

                if(!$beanResult){
                    $beanstalkdQueue->insert($beansData);
                }

            } else {
                $beanstalkdQueue->insert($beansData);
            }

        }

        if ($result->code == $result::CODE_SUCCESS) {
            //Insert Verifikasi
            $dataVerifs = $data['verifs'] ?? null;
            $user = $naf->user(true);

            if ($dataVerifs) {
                //Save Uji Petik
                $this->saveEvidenceUjiPetik($order, $dataVerifs);

                //Update Attribute
                $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

                $dataVerifs = AttributesMap::setMapData($dataVerifs, $verifBy);

                $dataAttributeVerification = array();

                if (isset($dataOrder->data['attributes']) && $dataOrder->data['attributes']) {
                    $dataAttributeVerification = AttributesMap::attributeDetails($order['attributes']);
                    $dataAttributeVerification = $dataAttributeVerification[AttributesMap::TIPE_ATTRIBUTES[$verifBy]];
                }

                foreach ($dataVerifs as $key => $attributes) {
                    $find = AttributesMap::searchBy($dataAttributeVerification, $attributes['xn1'], $data['order_id'], $attributes['order_attribute_type_id']);

                    if ($find) {
                        $attributes['order_attribute_id'] = $find['orderAttributeId'];
                        $save = \Neuron\Order\Attribute\Storage::SAVEBY_ID;
                        $attributes['xn4'] = $user->id;
                    } else {
                        $save = \Neuron\Order\Attribute\Storage::SAVE_INSERT;
                        if (!isset($attributes['xn3'])) {
                            $attributes['xn3'] = $user->id;
                        }
                    }

                    $attributes['xn5']      = $attributes['xn2'];
                    $attributes['order_id'] = $data['order_id'];

                    $attribute->saveEx($attributes, $save);
                }

                //Save Existing
                $dataOrderAttribute = OrderMap::getMapData($dataOrder->data);
                $this->saveUpdateAttributesEvidence(array('attributes' => $dataVerifs), $dataOrderAttribute, OrderMap::APPROVED_ACTION);
            }

            $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());

            $OrderAddon->updateTimelineOrder($data['order_id'],'STARTSUBMIT');

            //Save Activity
            $dataActivity = [
                'order_id'  => $data['order_id'],
                'xn2'       => $user->id,
                'xs1'       => 'MILESTONE',
                'xs2'       => 'APPROVE ' . $session['active_role']['name'],
                'xs3'       => $user->code,
                'xs4'       => 'APPROVED',
                'xs5'       => json_encode($data),
                'xs6'       => $session['active_role']['code'],
                'xs7'       => $session['active_role']['name']
            ];

            $this->saveOrderActivities($dataActivity);

            

        }

        return $result;
    }

    public function getDataFromDatek($data){
        // print_r($data);die;
        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
    
        try {

            $tokenSIT = $this->getTokenSIT('comwa');

            if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
                throw new \Exception('Gagal Update ODP UIM . Error : Gagal token',5);
            }
         
            $url      = $naf->getController()->getConfig()['api']['getServiceInfo']['Url'];
            
            $params = [
                'getServiceInfoRequest' => [
                    'eaiHeader' => [
                        'externalId' => $data['nomorService'],
                        'timestamp'=> date('Y-m-d H:i:s'),
                    ],
                    'eaiBody' => [
                        'ND' => $data['nomorService']
                    ]
                ]

            ];
        

            $client = new Client();
            $client->setUri($url);

            $client->setHeaders(array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
            ));

            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                    );

                
          
            $client->setMethod('POST');
            $client->setRawBody(json_encode($params));
            $response = $client->send();

            $dataResponse     = json_decode($response->getBody(), true);

        
            $isEaiBody = $dataResponse['getServiceInfoResponse']['eaiBody'];
            
            // check not found
            if (!isset($isEaiBody)) {
                throw new \Exception('Gagal Update ODP UIM . Error : Data tidak ditemukan',5);
            }

            $result->code = $isEaiBody['statusCode'] == '4000' ? Result::CODE_SUCCESS: 1;
            $result->info = $isEaiBody['statusCode'] == '4000' ? Result::INFO_SUCCESS: 'Gagal Update ODP UIM . Error : '.$isEaiBody['statusMessage'];
            $result->data = $isEaiBody['statusCode'] == '4000' ? $isEaiBody : null;

            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal Update ODP UIM .';
            
        }

        $dataActivity = [
            'order_id'  => $data['orderId'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'APi',
            'xs2'       => 'HIT API GET SERVICE INFO DATEK',
            'xs3'       => $result->info,
            'xs4'       => json_encode($params),
            'xs5'       => json_encode($dataResponse) ?? null
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        return $result;

    }

    public function getPaketFromSc($data){
        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
    
        try {

            $tokenSIT = $this->getTokenSIT('comwa');
        
            if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
                throw new \Exception('Gagal info paket SC . Error : Gagal token',5);
            }


         
            $url      = $naf->getController()->getConfig()['api']['trackingOrderSC']['Url'];

            $sc = strpos($data['Sc'],"SC") !== false ? substr($data['Sc'],2) : $data['Sc'];
            
            $params = [
                'guid' => 0,
                'code' => 0,
                'data' => [
                    'field' => 'ORDER_ID',
                    'searchText' =>$sc,
                    'source'=>'NOSS',
                    'typeMenu'=>'TRACKING'
                ],
            ];
            

            $client = new Client();
            $client->setUri($url);

            $client->setHeaders(array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
            ));

            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                    );

                
          
            $client->setMethod('POST');
            $client->setRawBody(json_encode($params));
            $response = $client->send();

            $dataResponse     = json_decode($response->getBody(), true);

            $result->code = $dataResponse['trackingOrderResponse']['eaiBody']['data'][0] ? Result::CODE_SUCCESS : 1;
            $result->info = $dataResponse['trackingOrderResponse']['eaiBody']['data'][0] ? Result::INFO_SUCCESS : 'Failed';
            $result->data = $dataResponse['trackingOrderResponse']['eaiBody']['data'][0] ?? null;

            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal info paket SC.';
            
        }

        $dataActivity = [
            'order_id'  => $data['orderId'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'API',
            'xs2'       => 'HIT API GET PAKET',
            'xs3'       => $result->info,
            'xs4'       => json_encode($params),
            'xs5'       => json_encode($dataResponse) ?? null
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        return $result;

    }

    public function listTeknisiFromIdmt($data){

        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
    
        try {

            $tokenSIT = $this->getTokenSIT('comwa');
        
            if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
                throw new \Exception('Gagal List Teknisi . Error : Gagal token',5);
            }

            $url      = $naf->getController()->getConfig()['api']['listTechnician']['Url'];
            
            $params = [
                'listTechnicianRequest' => [
                    'eaiHeader' => [
                        'externalId' => '',
                        'timestamp'=> '',
                    ],
                    'eaiBody' => [
                        'filter_sto' => $data['sto']
                    ]
                ]
            ];
            

            $client = new Client();
            $client->setUri($url);

            $client->setHeaders(array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
            ));

            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                    );

                
          
            $client->setMethod('POST');
            $client->setRawBody(json_encode($params));
            $response = $client->send();

            $dataResponse     = json_decode($response->getBody(), true);

            $isSuccess = isset($dataResponse['listTechnicianResponse']['eaiBody']['data']) && $dataResponse['listTechnicianResponse']['eaiBody']['data'] ;

            $result->code = $isSuccess ? Result::CODE_SUCCESS : 1;
            $result->info = $isSuccess ? Result::INFO_SUCCESS : 'Failed';
            $result->data = $isSuccess ? $dataResponse['listTechnicianResponse']['eaiBody']['data'] : null;

            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal List Teknisi.';
            
        }

        $dataActivity = [
            'order_id'  => $data['orderId'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'API',
            'xs2'       => 'HIT API GET Teknisi',
            'xs3'       => $result->info,
            'xs4'       => json_encode($params),
            'xs5'       => json_encode($dataResponse) ?? null
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        return $result;


    }

    public function  sendInfracare($dataTicketInfracare,$dataOrder,$dataDetails){
      
        $minio = new \Neuron\Addon\Evidence\UTOnline\Minio;
        foreach ($dataTicketInfracare as $valueTicketInfracare) {

           if ($valueTicketInfracare['statusTicket'] == 2 || $valueTicketInfracare['statusTicket'] == 4) { // closed

                $reopenTicket = $this->reopenTicket([
                    'ticketid' => $valueTicketInfracare['ticketId'],
                    'order_id' => $dataOrder['order_id']
                ]);

                $dataUpdate['order_detail_id'] =  $dataDetails['orderDetailId'];

                if ($valueTicketInfracare['codeLabel'] == 'ODP03') {
                    $dataUpdate['xn1'] = $reopenTicket->code != 0 ? 4 : 1;
                }else if ($valueTicketInfracare['codeLabel'] == 'ODP06' ) {
                    $dataUpdate['xn2'] =  $reopenTicket->code != 0 ? 4 : 1;
                }else if ($valueTicketInfracare['codeLabel'] == 'ODP13' ) {
                    $dataUpdate['xn3'] =  $reopenTicket->code != 0 ? 4 : 1;
                }else if($valueTicketInfracare['codeLabel'] == 'ODP09'){
                    $dataUpdate['xn5'] =  $reopenTicket->code != 0 ? 4 : 1;
                }

                $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
                $detailService->saveDetails($dataUpdate);

           }else{
                
                $sendTicket =  $this->sendApiInfraCare(['attribute_id'=>$valueTicketInfracare['attributeId'],'label' => $valueTicketInfracare['label'],'path' => $valueTicketInfracare['path'] ],$dataOrder);
                
                if ($sendTicket->code == 0) {

                        
                        $find = AttributesMap::searchByEvidence($dataOrder['attributes'], $valueTicketInfracare['attributeId'], $dataOrder['order_id'], AttributesMap::TIPE_EVIDENCE_TEKNISI);
                       
                        if ($find) {
            
                            $base64image = $minio->loadImageAws($find['path'],false);
                            $dataDoclinks["DOCTYPE"] = "Attachments";
                            $dataDoclinks["URLNAME"] = $sendTicket->data."INVALID_EVIDENCE.jpg";
                            $dataDoclinks["URLTYPE"] = "FILE";
                            $dataDoclinks["PRINTTHRULINK"] = "0";
                            $dataDoclinks["DESCRIPTION"] = $sendTicket->data." tidak valid uji petik ".$valueTicketInfracare['label'];
                            $dataDoclinks["DOCUMENT"] = "EVIDENCE_BEFORE";
                            $dataDoclinks["DOCUMENTDATA"] = str_replace("data:image/jpg;base64,","",$base64image);
            
                        }    
            
                        $regionalMap = [
                            'REGIONAL_1' => 'REG-1',
                            'REGIONAL_2' => 'REG-2',
                            'REGIONAL_3' => 'REG-3',
                            'REGIONAL_4' => 'REG-4',
                            'REGIONAL_5' => 'REG-5',
                            'REGIONAL_6' => 'REG-6',
                            'REGIONAL_7' => 'REG-7'
                        ];
            
                        $dataAttachment = [
                            "SITEID" => $regionalMap[$dataOrder['regional']],
                            "TICKETID" => $sendTicket->data,
                            "CLASS" => "INCIDENT",
                            "DOCLINKS" => $dataDoclinks,
                        ];
                            
                        $sendAttachment = $this->sendAttachmentTicket($dataAttachment,$dataOrder['order_id']);
                                           
                }

                $dataUpdate['order_detail_id'] =  $dataDetails['orderDetailId'];
                $dataUpdate['xn6'] =  1;
                        
                if ($valueTicketInfracare['codeLabel'] == 'ODP03') {
                    $dataUpdate['xn1'] = $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                    if ($sendTicket->code == 0) {
                        $dataUpdate['xs1'] = $sendTicket->data;
                    }
                    $dataUpdate['xs2'] = $valueTicketInfracare['label'];
                    $dataUpdate['xs3'] =  $valueTicketInfracare['attributeId'];
                }
                
                if ($valueTicketInfracare['codeLabel'] == 'ODP06' ) {
                    $dataUpdate['xn2'] =  $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                    if ($sendTicket->code == 0) {
                        $dataUpdate['xs4'] = $sendTicket->data;
                    }
                    $dataUpdate['xs5'] = $valueTicketInfracare['label'];
                    $dataUpdate['xs6'] =  $valueTicketInfracare['attributeId'];
                }
                
                if ($valueTicketInfracare['codeLabel'] == 'ODP13' ) {
                    $dataUpdate['xn3'] =   $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                    if ($sendTicket->code == 0) {
                        $dataUpdate['xs7'] = $sendTicket->data;
                    }
                    $dataUpdate['xs8'] = $valueTicketInfracare['label'];
                    $dataUpdate['xs9'] =  $valueTicketInfracare['attributeId'];
                }

                if ($valueTicketInfracare['codeLabel'] == 'ODP09' ) {
                    $dataUpdate['xn5'] =   $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                    if ($sendTicket->code == 0) {
                        $dataUpdate['xs10'] = $sendTicket->data;
                    }
                    $dataUpdate['xs11'] =  $valueTicketInfracare['label'];
                    $dataUpdate['xs12'] =  $valueTicketInfracare['attributeId'];
                }


                $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
                $detailService->saveDetails($dataUpdate);


           }
        }

        return true;
    }

    public function sendApiInfraCare($data,$order){

        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
        
        $dataOdp = DetailsMap::collectdetails($order['details'], DetailsMap::DATEK, false);
        
        try {

            $tokenSIT = $this->getTokenSIT('utonline');
            if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
                throw new \Exception('Gagal create tiket  . Error : Gagal token',5);
            }                 
            $url      = $naf->getController()->getConfig()['api']['createTKIncident']['Url'];
            
           
            $params = [
                'CLASS' => 'INCIDENT',
                'DESCRIPTION' => $data['label'].'||'.$dataOdp['odp_name'].'||'.$dataOdp['lat'].','.$dataOdp['long'],
                'DESCRIPTION_LONGDESCRIPTION' => $data['label'],
                'EXTERNALSYSTEM' => 'PROACTIVE_TICKET',
                'HIERARCHYPATH' =>$data['path'],
                'INTERNALPRIORITY'=> '2',
                'REPORTEDBY'=> 'QCPSB',
                'TK_CHANNEL'=>'58',
                'TK_WORKZONE'=> $order['sto'],
                'TK_ESTIMASI'=> '4',
                'TK_TICKET_81'=> $order['scId'],
                'TKCUSTOMERSEGMENT' => substr($order['segment'],0,3),
                'ASSETNUM'=> 'DEFAULT_NN_NAS',
            ];
          
            $client = new Client();
            $client->setUri($url);

            $client->setHeaders(array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
            ));

            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                    );
            $client->setMethod('POST');
            $client->setRawBody(json_encode($params,JSON_UNESCAPED_SLASHES));
            $response = $client->send();

            $dataResponse     = json_decode($response->getBody(), true);
            $result->code = isset($dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID']) ? Result::CODE_SUCCESS : 1;
            $result->info = isset($dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID']) ? Result::INFO_SUCCESS : 'Failed';
            $result->data = isset($dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID']) ?  $dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID'] : null;
            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal create ticket.';
            
        }

        $dataActivity = [
            'order_id'  => $order['order_id'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'API',
            'xs2'       => 'HIT API GET TICKET NOSSA',
            'xs3'       => $result->info,
            'xs4'       => json_encode($params,JSON_UNESCAPED_SLASHES),
            'xs5'       => json_encode($dataResponse) ?? null,
            'xs9'       => $data['label'],
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        $dataMilestoneActivity = [
            'order_id'  => $order['order_id'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'MILESTONE',
            'xs2'       => $result->code == Result::CODE_SUCCESS ? 'SUCCESS CREATE TICKET NOSSA' :  'FAILED CREATE TICKET NOSSA' ,
            'xs3'       => $user->code,
            'xs4'       => $result->info,
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataMilestoneActivity);

        return $result;

    }

    public function sendAttachmentTicket($params,$orderid){
        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
    
        try {

            $tokenSIT = $this->getTokenSIT('utonline');
            if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
                throw new \Exception('Gagal upload ticket  . Error : Gagal token',5);
            }                 
            $url      = $naf->getController()->getConfig()['api']['attachmentTicket']['Url'];
           
            $client = new Client();
            $client->setUri($url);

            $client->setHeaders(array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
            ));

            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                    );
            
            $client->setMethod('POST');
            $client->setRawBody(json_encode($params));
            
            $response = $client->send();

            $dataResponse     = json_decode($response->getBody(), true);

            $result->code = $dataResponse['attachmentTicketResponse']['document']['statusCode'] == '200' ? Result::CODE_SUCCESS : 1;
            $result->info = $dataResponse['attachmentTicketResponse']['document']['statusCode'] == '200' ? Result::INFO_SUCCESS : $dataResponse['attachmentTicketResponse']['document']['returnMessage'] ;
            $result->data = $dataResponse['attachmentTicketResponse']['document']['statusCode'] == '200' ? $dataResponse['attachmentTicketResponse']['document']['returnMessage'] : null;
            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal upload ticket.';
            
        }

        $dataActivity = [
            'order_id'  => $orderid,
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'API',
            'xs2'       => 'HIT API UPLOAD TICKET',
            'xs3'       => $result->info,
            'xs4'       => json_encode($params),
            'xs5'       => json_encode($dataResponse) ?? null
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        $dataMilestoneActivity = [
            'order_id'  => $order['order_id'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'MILESTONE',
            'xs2'       => $result->code == Result::CODE_SUCCESS ? 'SUCCESS ATTACHMENT IMAGE TICKET NOSSA' :  'FAILED ATTACHMENT IMAGE TICKET NOSSA',
            'xs3'       => $user->code,
            'xs4'       => $result->info,
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataMilestoneActivity);

        return $result;
    }

    public function reopenTicket($data){

        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
    
        try {

            $tokenSIT = $this->getTokenSIT('utonline');
            if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
                throw new \Exception('Gagal create tiket  . Error : Gagal token',5);
            }                 
            $url      = $naf->getController()->getConfig()['api']['reopenTicket']['Url'];
            
           
            $params = [
               'updateINCIDENTAPI' => [
                    'INCIDENTAPISet' => [
                        'INCIDENT' => [
                            'CLASS' => 'INCIDENT',
                            'TICKETID' => $data['ticketid'],
                            'TK_API' => 'REOPEN'
                        ]
                    ]
               ]
            ];

            $client = new Client();
            $client->setUri($url);

            $client->setHeaders(array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Bearer '.$tokenSIT['data']['access_token'],
                'Accept'        => 'application/json',
            ));

            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                    );
            $client->setMethod('POST');
            $client->setRawBody( json_encode($params,JSON_UNESCAPED_SLASHES));
            $response = $client->send();

            $dataResponse     = json_decode($response->getBody(), true);
           
            $result->code =  isset($dataResponse['UpdateINCIDENTAPIResponse']['UpdateINCIDENTAPIResponse']['@creationDateTime']) ? Result::CODE_SUCCESS : 1;
            $result->info =  isset($dataResponse['UpdateINCIDENTAPIResponse']['UpdateINCIDENTAPIResponse']['@creationDateTime']) ? Result::INFO_SUCCESS : 'Failed';
            $result->data =  isset($dataResponse['UpdateINCIDENTAPIResponse']['UpdateINCIDENTAPIResponse']['@creationDateTime']) ?  null : null;
            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal reopen ticket.';
            
        }

        $dataActivity = [
            'order_id'  => $data['order_id'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'API',
            'xs2'       => 'HIT API REOPEN TICKET',
            'xs3'       => $result->info,
            'xs4'       => json_encode($params,JSON_UNESCAPED_SLASHES),
            'xs5'       => json_encode($dataResponse) ?? null
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        $dataMilestoneActivity = [
            'order_id'  => $data['order_id'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'MILESTONE',
            'xs2'       => $result->code == Result::CODE_SUCCESS ? 'SUCCESS REOPEN TICKET NOSSA' :  'FAILED REOPEN TICKET NOSSA',
            'xs3'       => $user->code,
            'xs4'       => $result->info,
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataMilestoneActivity);

        return $result;


    }

    public function sendTelegram($data){
        // cari telegram id
        $naf = Entity::get();
        $entity = new \Neuron\Addon\Evidence\UTOnline\Entity\User(
            \Neuron\Addon\Evidence\UTOnline\Entity\User\Storage::factory($naf->getDb(),
            $naf->base()->getModuleConfig())
        );

        if (isset($data['leader']) && $data['leader']) {
            $dataUser['where']['code'] = $data['leader'];
            $getTelegramId = $entity->getUser($dataUser);
        }

        if (isset($data['sto']) && $data['sto']){
            $dataTelegramId = $entity->getTelegramID(['sto' => $data['sto']]);
            $joindataTelegram = "";

            foreach ($dataTelegramId as $valueTelegramId ) {
                $joindataTelegram .= $valueTelegramId['telegram_id'].",";
            }
            $getTelegramId['telegram_id'] = trim($joindataTelegram,",");
        }

       if (isset($getTelegramId['telegram_id']) && $getTelegramId['telegram_id']) {
            // load template code
            $telegramService = new \Neuron\Addon\Evidence\UTOnline\Services\TelegramService;

            // $loadTemplateCode = $telegramService->loadTemplateCode();
            // print_r($loadTemplateCode);die;

            // if ($loadTemplateCode == 1) {
            //     return false;
            // }

            // get template code

           // foreach ($loadTemplateCode->data as $valueItemTemplate) {

               // if ($valueItemTemplate['template_code'] == 'ut_online_01') {
                    // send telegram
                    $dataSendTelegram = [
                        'template_code' => 'ut_online_01',
                        'subject' => $getTelegramId['telegram_id'],
                        'param' => [
                            'wo' => $data['order_wo'].'/ SC '.$data['scId'],
                            'status' => '<b> Invalid '.$data['role'].'</b> (Tanggal WO:'.$data['tglWO'].')',
                            'keterangan' => $data['keterangan']
                        ]
                    ];

                    $sendToTelegram = $telegramService->sendTelegram($dataSendTelegram,$data['order_id']);
                    
                    if ($sendToTelegram == 1) {
                        return false;
                    }

                    $dataActivity = [
                        'order_id'  => $data['order_id'],
                        'xs1'       => 'MILESTONE',
                        'xs2'       => 'SUKSES KIRIM TELEGRAM',
                        'xs3'       => $data['leader'],
                    ];
                    $activity = new OrderService;
                    $activity->saveOrderActivities($dataActivity);

               // }
               
           // }

            // print_r($loadTemplateCode);die;
       }else{

            $dataActivity = [
                'order_id'  => $data['order_id'],
                'xs1'       => 'MILESTONE',
                'xs2'       => 'GAGAL KIRIM TELEGRAM | TIDAK ADA TELEGRAM ID',
                'xs3'       => $data['leader'],
            ];
            $activity = new OrderService;
            $activity->saveOrderActivities($dataActivity);

       }
       
       return true;
       
    }
    
    public function formatKeteranganTelegramPiloting($dataVerifs,$keteranganReturn,$fotoTidakSesuai,$dataLabelfotoNotvalid,$dataOrder,$tlta){
    
        $ujipetikinvalid = [];
        $dataKeterangan = "".PHP_EOL;
        $increment=0;

        $dataKeterangan .= "<b>Teknisi : </b>".$dataOrder['laborCode'].PHP_EOL;
        $dataKeterangan .= "<b>Agent TL TA  :</b> ".$tlta.PHP_EOL;
        
        if ($fotoTidakSesuai == 1 ) {

            $increment++;
            $dataKeterangan .= '<b>'. $increment.'. Foto tidak sesuai dengan label  : </b>'.PHP_EOL;
            foreach ($dataLabelfotoNotvalid as $valueLabelNotvalid) {
                $dataKeterangan  .= '- '.$valueLabelNotvalid->xs6 .' : '.$valueLabelNotvalid->xs4.PHP_EOL; 
            }
            
        }

        $splitKeterangan = explode(",",$keteranganReturn);
       
       
        if ($splitKeterangan[0] != '-'){ // cek spek invalid
            $increment++;
            $dataKeterangan .= '<b>'.$increment.'. Hasil Ukur spec tidak sesuai</b> : '.$splitKeterangan[0].'.'.PHP_EOL;
        }

        if ($splitKeterangan[1] != '-') { // Material invalid
            $increment++;
            $dataKeterangan .= '<b>'.$increment.'. Data Material tidak sesuai</b> : '.$splitKeterangan[1].'.'.PHP_EOL;
        }

        if ($splitKeterangan[2] != '-') { // BA invalid
            $increment++;
            $dataKeterangan .= '<b>'.$increment.'. BA tidak sesuai</b> : '.$splitKeterangan[2].'.'.PHP_EOL;
        }

        return $dataKeterangan;

    }

    public function formatKeteranganTelegramNonPiloting($dataNotValid,$keteranganReturn,$dataLabelfotoNotvalid,$dataOrder){

        $keteranganNotValid = "".PHP_EOL;
        $increment = 0;

        if ($dataNotValid['materialTidakSesuai']) {
            $increment++;
            $keteranganNotValid .= '<b>'.$increment.'. Data Material tidak sesuai</b>'.PHP_EOL;
        }

        if ($dataNotValid['fotoTidakSesuai']) {
            $increment++;
            $keteranganNotValid .='<b>'.$increment.'. Foto tidak sesuai dengan label</b>:'.PHP_EOL;
            foreach ($dataLabelfotoNotvalid as $valueLabelNotvalid) {
                $keteranganNotValid  .= '- '.$valueLabelNotvalid->xs6 .' : '.$valueLabelNotvalid->xs4.PHP_EOL; 
            }
        }

        if ($dataNotValid['baTidakSesuai']) {
            $increment++;
            $keteranganNotValid .='<b>'.$increment.'. BA tidak sesuai </b>'.PHP_EOL;
        }

        if ($dataNotValid['hasilUkurKosong']) {
            $increment++;
            $keteranganNotValid .='<b>'.$increment.'. Hasil Ukur spec tidak sesuai</b>'.PHP_EOL;
        }

        if ($dataNotValid['tanggalTidakSesuai']) {
            $increment++;
            $keteranganNotValid .='<b>'.$increment.'. Tanggal tidak sesuai</b>'.PHP_EOL;
        }

        if ($dataNotValid['koordinatTidakSesuai']) {
            $increment++;
            $keteranganNotValid .='<b>'.$increment.'. koordinat tidak sesuai</b>'.PHP_EOL;
        }

        return $keteranganNotValid.'<b>Keterangan : </b>'.$keteranganReturn;

    }

    public function getImageQc1($data){


        $result = new Result;
        $naf = Entity::get();
        $user = $naf->user(true);
    
        try {
         
            $url      = $naf->getController()->getConfig()['api']['getImageQc1']['Url'];
            $username = $naf->getController()->getConfig()['api']['getImageQc1']['Username'];
            $password = $naf->getController()->getConfig()['api']['getImageQc1']['Password'];

            $request = new Request();
            $request->getHeaders()->addHeaders(array(

                'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'
                
            ));
           
            $arrParam = array(
                "trackId" => $data['trackid']
            );
            
            $jsonParam = json_encode($arrParam);

            $request->setUri($url);
            $request->setMethod('POST');
            $arraydata = array(

                'data'  =>  $jsonParam
                
            );
           
            $request->setPost(new Parameters($arraydata));
            

            $client = new Client();

            $client->setAuth($username, $password, \Zend\Http\Client::AUTH_BASIC);
            $client->setOptions(
                        array('timeout'=>120,
                            'persistent' => false,
                            'sslverifypeer' => false,
                            'sslallowselfsigned' => false,
                            'sslusecontext' => false,
                            'ssl' => array(
                                'verify_peer' => false,
                                'allow_self_signed' => true,
                                'capture_peer_cert' => true,
                            ),
                        )
                );
          
            $response = $client->dispatch($request);
            
            $dataResponse = json_decode($response->getBody(), true);

            $result->code = $dataResponse['code'];
            $result->info = $dataResponse['info'];
            $result->data = 'data:image/jpg;base64,'.$dataResponse['data'];
    

            
        } catch (\Exception $ex) {

            $result->code = $ex->getCode() == 5 ? 1 : 2;
            $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal get info image';
            
        }

        $dataActivity = [
            'order_id'  => $data['orderId'],
            'xn2'       => $user->id ?? 1,
            'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
            'xs1'       => 'API',
            'xs2'       => 'HIT API GET FOTO RUMAH QC1',
            'xs3'       => $result->info,
            'xs4'       => $arraydata,
            'xs5'       => $result->code == 0 ? null : $dataResponse
        ];

        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

        return $result;

    }

    public function saveInvalidSymptom($order, $dataVerifs)
    {
       
        $naf = Entity::get();

        $dataInput = array();
        foreach ($dataVerifs as $key => $verif) {
            if (isset($verif['symptomInvalid']) && $verif['symptomInvalid']) {
                foreach ($verif['symptomInvalid'] as $itemSymptomInavlid) {
                    $dataInput[] = [
                        'order_id'              => $order['order_id'],
                        'order_attribute_id'    => $verif['xn1'],
                        'code'                  => $itemSymptomInavlid['id'],
                        'label'                 => $itemSymptomInavlid['label']
                    ];
                }
            }
        }

      //  print_r($dataInput);die;

        $symptomInvalid = new \Neuron\Addon\Evidence\UTOnline\Entity\OrderSymptomInvalid(\Neuron\Addon\Evidence\UTOnline\Entity\OrderSymptomInvalid\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        if (!empty($dataInput)) {
            //Cleansing Exist Data
            $symptomInvalid->delete($order['order_id'], OrderSymptomInvalid\Storage::DELETEBY_ORDERID);

            foreach ($dataInput as $ujp) {
                $saveInsert = $symptomInvalid->saveEx($ujp, \Neuron\Order\Detail\Storage::SAVE_INSERT);
            }
        }

        return true;
    }

    public function getSymtompInvalid($evidence)
    {
        $naf = Entity::get();

        $ujiPetik = new OrderSymptomInvalid(OrderSymptomInvalid\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
        $checkUjp = $ujiPetik->loadList($evidence['attributeId'], OrderSymptomInvalid\Storage::LOADBY_ATTR_ID);
       
        return $checkUjp->data ?? null;
    }

    public function getEvidenceToSendTicket($dataOrder,$attributeId,$ticketId){

        $minio = new \Neuron\Addon\Evidence\UTOnline\Minio;

        $find = AttributesMap::searchByEvidence($dataOrder['attributes'], $attributeId, $dataOrder['order_id'], 7);
                       
        if ($find) {

            $base64image = $minio->loadImageAws($find['path'],false);
            $dataDoclinks["DOCTYPE"] = "Attachments";
            $dataDoclinks["URLNAME"] = $ticketId."INVALID_EVIDENCE.jpg";
            $dataDoclinks["URLTYPE"] = "FILE";
            $dataDoclinks["PRINTTHRULINK"] = "0";
            $dataDoclinks["DESCRIPTION"] = $ticketId." tidak valid uji petik ".$itemRetryTicket['label'];
            $dataDoclinks["DOCUMENT"] = "EVIDENCE_BEFORE";
            $dataDoclinks["DOCUMENTDATA"] = str_replace("data:image/jpg;base64,","",$base64image);

        }    

        $regionalMap = [
            'REGIONAL_1' => 'REG-1',
            'REGIONAL_2' => 'REG-2',
            'REGIONAL_3' => 'REG-3',
            'REGIONAL_4' => 'REG-4',
            'REGIONAL_5' => 'REG-5',
            'REGIONAL_6' => 'REG-6',
            'REGIONAL_7' => 'REG-7'
        ];

        $dataAttachment = [
            "SITEID" => $regionalMap[$dataOrder['regional']],
            "TICKETID" => $sendTicket->data,
            "CLASS" => "INCIDENT",
            "DOCLINKS" => $dataDoclinks,
        ];
        
        return $dataAttachment;

    }

    public function updateStatusTicket($dataUpdateTicket){

        $messageTicket = [];
       

        if (isset($dataUpdateTicket['resultCreateTicket']) && $dataUpdateTicket['resultCreateTicket'] ) {
            $statusTicket = $dataUpdateTicket['resultCreateTicket'] == 0 ? ($dataUpdateTicket['resultAttachmentTicket'] == 0 ? 1 : 5) : 3;
        }else if(isset($dataUpdateTicket['resultReopenTicket']) && $dataUpdateTicket['resultReopenTicket'] ){
            $statusTicket = $dataUpdateTicket['resultReopenTicket'] == 0 ? 1 : 4; 
        }else {
            $statusTicket = $dataUpdateTicket['resultAttachmentTicket'] == 0 ? 1 : 5;
        }

        $dataUpdate['order_detail_id'] =  $dataUpdateTicket['detail_id'];
        $messageStatus = $statusTicket == 1 ? 'Open' : ($statusTicket == 3 ? 'Falied Create Ticket' : ($statusTicket == 5 ? 'Failed Upload attcahment ticket' : '-'));
                        
        if ($dataUpdateTicket['flag'] == 'ODP_TIDAK_BERSIH') {
            $dataUpdate['xn1'] = $statusTicket;
            $dataUpdate['xs1'] = $dataUpdateTicket['ticket_id'];
            $dataUpdate['xs2'] = $dataUpdateTicket['label'];
            $dataUpdate['xs3'] =  $dataUpdateTicket['attribute_id'];
            $messageTicket = [
                'ticket_id' => $dataUpdateTicket['ticket_id'],
                'status'    => $messageStatus, 
            ];
        }
        
        if ($dataUpdateTicket['flag']  == 'ODP_GENDONG' ) {
            $dataUpdate['xn2'] = $statusTicket;
            $dataUpdate['xs4'] = $dataUpdateTicket['ticket_id'];
            $dataUpdate['xs5'] = $dataUpdateTicket['label'];
            $dataUpdate['xs6'] = $dataUpdateTicket['attribute_id'];
            $messageTicket = [
                'ticket_id' => $dataUpdateTicket['ticket_id'],
                'status'    => $messageStatus, 
            ];
        }
        
        if ($dataUpdateTicket['flag'] == 'ODP_TIDAK_ADA' ) {
            $dataUpdate['xn3'] = $statusTicket;
            $dataUpdate['xs7'] = $dataUpdateTicket['ticket_id'];
            $dataUpdate['xs8'] = $dataUpdateTicket['label'];
            $dataUpdate['xs9'] = $dataUpdateTicket['attribute_id'];
            $messageTicket = [
                'ticket_id' => $dataUpdateTicket['ticket_id'],
                'status'    => $messageStatus, 
            ];
        }

        if ($dataUpdateTicket['flag'] == 'ODP_PINTU_HILANG' ) {
            $dataUpdate['xn5'] = $statusTicket;
            $dataUpdate['xs10'] = $dataUpdateTicket['ticket_id'];
            $dataUpdate['xs11'] = $dataUpdateTicket['label'];
            $dataUpdate['xs12'] = $dataUpdateTicket['attribute_id'];
            $messageTicket = [
                'ticket_id' => $dataUpdateTicket['ticket_id'],
                'status'    => $messageStatus, 
            ];
        }


        $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
        $detailService->saveDetails($dataUpdate);

        return $messageTicket;
    }

    //Queue ke Beanstalkd
    if($data['order_status_id'] == OrderMap::APPROVED_CODE){

        $beanstalkdQueue = new \Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue(
            \Neuron\Addon\Evidence\UTOnline\Entity\BeanstalkdQueue\Storage::factory(
                $naf->getDb(),
                $naf->getController()->getConfig()
            )
        );

        $tube = (isset($dataOrder->data[OrderMap::FRESH_ORDER]) && $dataOrder->data[OrderMap::FRESH_ORDER]) ? Beanstalkd::TUBE_REWORK_T1 : Beanstalkd::TUBE_FRESH_T1;
        $beansData = [
            'order_id'  => $result->data,
            'tube'      => $tube,
            'status'    => BeanstalkdQueue::STATUS_UNREAD,
        ];

        if ($beanstalk = new Beanstalkd($this)) {
            $beanResult = $beanstalk->putJob($tube, $result->data);

            if(!$beanResult){
                $beanstalkdQueue->insert($beansData);
            }

        } else {
            $beanstalkdQueue->insert($beansData);
        }

    }

    if ($result->code == $result::CODE_SUCCESS) {
        //Insert Verifikasi
        $dataVerifs = $data['verifs'] ?? null;
        $user = $naf->user(true);

        if ($dataVerifs) {
            //Save Uji Petik
            $this->saveEvidenceUjiPetik($order, $dataVerifs);

            //Update Attribute
            $attribute = new \Neuron\Order\Attribute(\Neuron\Order\Attribute\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));

            $dataVerifs = AttributesMap::setMapData($dataVerifs, $verifBy);

            $dataAttributeVerification = array();

            if (isset($dataOrder->data['attributes']) && $dataOrder->data['attributes']) {
                $dataAttributeVerification = AttributesMap::attributeDetails($order['attributes']);
                $dataAttributeVerification = $dataAttributeVerification[AttributesMap::TIPE_ATTRIBUTES[$verifBy]];
            }

            foreach ($dataVerifs as $key => $attributes) {
                $find = AttributesMap::searchBy($dataAttributeVerification, $attributes['xn1'], $data['order_id'], $attributes['order_attribute_type_id']);

                if ($find) {
                    $attributes['order_attribute_id'] = $find['orderAttributeId'];
                    $save = \Neuron\Order\Attribute\Storage::SAVEBY_ID;
                    $attributes['xn4'] = $user->id;
                } else {
                    $save = \Neuron\Order\Attribute\Storage::SAVE_INSERT;
                    if (!isset($attributes['xn3'])) {
                        $attributes['xn3'] = $user->id;
                    }
                }

                $attributes['xn5']      = $attributes['xn2'];
                $attributes['order_id'] = $data['order_id'];

                $attribute->saveEx($attributes, $save);
            }

            //Save Existing
            $dataOrderAttribute = OrderMap::getMapData($dataOrder->data);
            $this->saveUpdateAttributesEvidence(array('attributes' => $dataVerifs), $dataOrderAttribute, OrderMap::APPROVED_ACTION);
        }

        $OrderAddon = new OrderAddon($naf->getDb(), $naf->base()->getModuleConfig());

        $OrderAddon->updateTimelineOrder($data['order_id'],'STARTSUBMIT');

        //Save Activity
        $dataActivity = [
            'order_id'  => $data['order_id'],
            'xn2'       => $user->id,
            'xs1'       => 'MILESTONE',
            'xs2'       => 'APPROVE ' . $session['active_role']['name'],
            'xs3'       => $user->code,
            'xs4'       => 'APPROVED',
            'xs5'       => json_encode($data),
            'xs6'       => $session['active_role']['code'],
            'xs7'       => $session['active_role']['name']
        ];

        $this->saveOrderActivities($dataActivity);

    }

    return $result;
}

public function getDataFromDatek($data){
    // print_r($data);die;
    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);

    try {

        $tokenSIT = $this->getTokenSIT('comwa');

        if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
            throw new \Exception('Gagal Update ODP UIM . Error : Gagal token',5);
        }
     
        $url      = $naf->getController()->getConfig()['api']['getServiceInfo']['Url'];
        
        $params = [
            'getServiceInfoRequest' => [
                'eaiHeader' => [
                    'externalId' => $data['nomorService'],
                    'timestamp'=> date('Y-m-d H:i:s'),
                ],
                'eaiBody' => [
                    'ND' => $data['nomorService']
                ]
            ]

        ];
    

        $client = new Client();
        $client->setUri($url);

        $client->setHeaders(array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
        ));

        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
                );

            
      
        $client->setMethod('POST');
        $client->setRawBody(json_encode($params));
        $response = $client->send();

        $dataResponse     = json_decode($response->getBody(), true);

    
        $isEaiBody = $dataResponse['getServiceInfoResponse']['eaiBody'];
        
        // check not found
        if (!isset($isEaiBody)) {
            throw new \Exception('Gagal Update ODP UIM . Error : Data tidak ditemukan',5);
        }

        $result->code = $isEaiBody['statusCode'] == '4000' ? Result::CODE_SUCCESS: 1;
        $result->info = $isEaiBody['statusCode'] == '4000' ? Result::INFO_SUCCESS: 'Gagal Update ODP UIM . Error : '.$isEaiBody['statusMessage'];
        $result->data = $isEaiBody['statusCode'] == '4000' ? $isEaiBody : null;

        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal Update ODP UIM .';
        
    }

    $dataActivity = [
        'order_id'  => $data['orderId'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'APi',
        'xs2'       => 'HIT API GET SERVICE INFO DATEK',
        'xs3'       => $result->info,
        'xs4'       => json_encode($params),
        'xs5'       => json_encode($dataResponse) ?? null
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    return $result;

}

public function getPaketFromSc($data){
    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);

    try {

        $tokenSIT = $this->getTokenSIT('comwa');
    
        if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
            throw new \Exception('Gagal info paket SC . Error : Gagal token',5);
        }


     
        $url      = $naf->getController()->getConfig()['api']['trackingOrderSC']['Url'];

        $sc = strpos($data['Sc'],"SC") !== false ? substr($data['Sc'],2) : $data['Sc'];
        
        $params = [
            'guid' => 0,
            'code' => 0,
            'data' => [
                'field' => 'ORDER_ID',
                'searchText' =>$sc,
                'source'=>'NOSS',
                'typeMenu'=>'TRACKING'
            ],
        ];
        

        $client = new Client();
        $client->setUri($url);

        $client->setHeaders(array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
        ));

        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
                );

            
      
        $client->setMethod('POST');
        $client->setRawBody(json_encode($params));
        $response = $client->send();

        $dataResponse     = json_decode($response->getBody(), true);

        $result->code = $dataResponse['trackingOrderResponse']['eaiBody']['data'][0] ? Result::CODE_SUCCESS : 1;
        $result->info = $dataResponse['trackingOrderResponse']['eaiBody']['data'][0] ? Result::INFO_SUCCESS : 'Failed';
        $result->data = $dataResponse['trackingOrderResponse']['eaiBody']['data'][0] ?? null;

        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal info paket SC.';
        
    }

    $dataActivity = [
        'order_id'  => $data['orderId'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'API',
        'xs2'       => 'HIT API GET PAKET',
        'xs3'       => $result->info,
        'xs4'       => json_encode($params),
        'xs5'       => json_encode($dataResponse) ?? null
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    return $result;

}

public function listTeknisiFromIdmt($data){

    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);

    try {

        $tokenSIT = $this->getTokenSIT('comwa');
    
        if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
            throw new \Exception('Gagal List Teknisi . Error : Gagal token',5);
        }

        $url      = $naf->getController()->getConfig()['api']['listTechnician']['Url'];
        
        $params = [
            'listTechnicianRequest' => [
                'eaiHeader' => [
                    'externalId' => '',
                    'timestamp'=> '',
                ],
                'eaiBody' => [
                    'filter_sto' => $data['sto']
                ]
            ]
        ];
        

        $client = new Client();
        $client->setUri($url);

        $client->setHeaders(array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
        ));

        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
                );

            
      
        $client->setMethod('POST');
        $client->setRawBody(json_encode($params));
        $response = $client->send();

        $dataResponse     = json_decode($response->getBody(), true);

        $isSuccess = isset($dataResponse['listTechnicianResponse']['eaiBody']['data']) && $dataResponse['listTechnicianResponse']['eaiBody']['data'] ;

        $result->code = $isSuccess ? Result::CODE_SUCCESS : 1;
        $result->info = $isSuccess ? Result::INFO_SUCCESS : 'Failed';
        $result->data = $isSuccess ? $dataResponse['listTechnicianResponse']['eaiBody']['data'] : null;

        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal List Teknisi.';
        
    }

    $dataActivity = [
        'order_id'  => $data['orderId'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'API',
        'xs2'       => 'HIT API GET Teknisi',
        'xs3'       => $result->info,
        'xs4'       => json_encode($params),
        'xs5'       => json_encode($dataResponse) ?? null
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    return $result;


}

public function  sendInfracare($dataTicketInfracare,$dataOrder,$dataDetails){
  
    $minio = new \Neuron\Addon\Evidence\UTOnline\Minio;
    foreach ($dataTicketInfracare as $valueTicketInfracare) {

       if ($valueTicketInfracare['statusTicket'] == 2 || $valueTicketInfracare['statusTicket'] == 4) { // closed

            $reopenTicket = $this->reopenTicket([
                'ticketid' => $valueTicketInfracare['ticketId'],
                'order_id' => $dataOrder['order_id']
            ]);

            $dataUpdate['order_detail_id'] =  $dataDetails['orderDetailId'];

            if ($valueTicketInfracare['codeLabel'] == 'ODP03') {
                $dataUpdate['xn1'] = $reopenTicket->code != 0 ? 4 : 1;
            }else if ($valueTicketInfracare['codeLabel'] == 'ODP06' ) {
                $dataUpdate['xn2'] =  $reopenTicket->code != 0 ? 4 : 1;
            }else if ($valueTicketInfracare['codeLabel'] == 'ODP13' ) {
                $dataUpdate['xn3'] =  $reopenTicket->code != 0 ? 4 : 1;
            }else if($valueTicketInfracare['codeLabel'] == 'ODP09'){
                $dataUpdate['xn5'] =  $reopenTicket->code != 0 ? 4 : 1;
            }

            $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
            $detailService->saveDetails($dataUpdate);

       }else{
            
            $sendTicket =  $this->sendApiInfraCare(['attribute_id'=>$valueTicketInfracare['attributeId'],'label' => $valueTicketInfracare['label'],'path' => $valueTicketInfracare['path'] ],$dataOrder);
            
            if ($sendTicket->code == 0) {

                    
                    $find = AttributesMap::searchByEvidence($dataOrder['attributes'], $valueTicketInfracare['attributeId'], $dataOrder['order_id'], AttributesMap::TIPE_EVIDENCE_TEKNISI);
                   
                    if ($find) {
        
                        $base64image = $minio->loadImageAws($find['path'],false);
                        $dataDoclinks["DOCTYPE"] = "Attachments";
                        $dataDoclinks["URLNAME"] = $sendTicket->data."INVALID_EVIDENCE.jpg";
                        $dataDoclinks["URLTYPE"] = "FILE";
                        $dataDoclinks["PRINTTHRULINK"] = "0";
                        $dataDoclinks["DESCRIPTION"] = $sendTicket->data." tidak valid uji petik ".$valueTicketInfracare['label'];
                        $dataDoclinks["DOCUMENT"] = "EVIDENCE_BEFORE";
                        $dataDoclinks["DOCUMENTDATA"] = str_replace("data:image/jpg;base64,","",$base64image);
        
                    }    
        
                    $regionalMap = [
                        'REGIONAL_1' => 'REG-1',
                        'REGIONAL_2' => 'REG-2',
                        'REGIONAL_3' => 'REG-3',
                        'REGIONAL_4' => 'REG-4',
                        'REGIONAL_5' => 'REG-5',
                        'REGIONAL_6' => 'REG-6',
                        'REGIONAL_7' => 'REG-7'
                    ];
        
                    $dataAttachment = [
                        "SITEID" => $regionalMap[$dataOrder['regional']],
                        "TICKETID" => $sendTicket->data,
                        "CLASS" => "INCIDENT",
                        "DOCLINKS" => $dataDoclinks,
                    ];
                        
                    $sendAttachment = $this->sendAttachmentTicket($dataAttachment,$dataOrder['order_id']);
                                       
            }

            $dataUpdate['order_detail_id'] =  $dataDetails['orderDetailId'];
            $dataUpdate['xn6'] =  1;
                    
            if ($valueTicketInfracare['codeLabel'] == 'ODP03') {
                $dataUpdate['xn1'] = $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                if ($sendTicket->code == 0) {
                    $dataUpdate['xs1'] = $sendTicket->data;
                }
                $dataUpdate['xs2'] = $valueTicketInfracare['label'];
                $dataUpdate['xs3'] =  $valueTicketInfracare['attributeId'];
            }
            
            if ($valueTicketInfracare['codeLabel'] == 'ODP06' ) {
                $dataUpdate['xn2'] =  $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                if ($sendTicket->code == 0) {
                    $dataUpdate['xs4'] = $sendTicket->data;
                }
                $dataUpdate['xs5'] = $valueTicketInfracare['label'];
                $dataUpdate['xs6'] =  $valueTicketInfracare['attributeId'];
            }
            
            if ($valueTicketInfracare['codeLabel'] == 'ODP13' ) {
                $dataUpdate['xn3'] =   $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                if ($sendTicket->code == 0) {
                    $dataUpdate['xs7'] = $sendTicket->data;
                }
                $dataUpdate['xs8'] = $valueTicketInfracare['label'];
                $dataUpdate['xs9'] =  $valueTicketInfracare['attributeId'];
            }

            if ($valueTicketInfracare['codeLabel'] == 'ODP09' ) {
                $dataUpdate['xn5'] =   $sendTicket->code == 0 ? ($sendAttachment->code == 0 ? 1 : 5 ) : 3;
                if ($sendTicket->code == 0) {
                    $dataUpdate['xs10'] = $sendTicket->data;
                }
                $dataUpdate['xs11'] =  $valueTicketInfracare['label'];
                $dataUpdate['xs12'] =  $valueTicketInfracare['attributeId'];
            }


            $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
            $detailService->saveDetails($dataUpdate);


       }
    }

    return true;
}

public function sendApiInfraCare($data,$order){

    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);
    
    $dataOdp = DetailsMap::collectdetails($order['details'], DetailsMap::DATEK, false);
    
    try {

        $tokenSIT = $this->getTokenSIT('utonline');
        if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
            throw new \Exception('Gagal create tiket  . Error : Gagal token',5);
        }                 
        $url      = $naf->getController()->getConfig()['api']['createTKIncident']['Url'];
        
       
        $params = [
            'CLASS' => 'INCIDENT',
            'DESCRIPTION' => $data['label'].'||'.$dataOdp['odp_name'].'||'.$dataOdp['lat'].','.$dataOdp['long'],
            'DESCRIPTION_LONGDESCRIPTION' => $data['label'],
            'EXTERNALSYSTEM' => 'PROACTIVE_TICKET',
            'HIERARCHYPATH' =>$data['path'],
            'INTERNALPRIORITY'=> '2',
            'REPORTEDBY'=> 'QCPSB',
            'TK_CHANNEL'=>'58',
            'TK_WORKZONE'=> $order['sto'],
            'TK_ESTIMASI'=> '4',
            'TK_TICKET_81'=> $order['scId']
        ];
      
        $client = new Client();
        $client->setUri($url);

        $client->setHeaders(array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
        ));

        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
                );
        $client->setMethod('POST');
        $client->setRawBody(json_encode($params,JSON_UNESCAPED_SLASHES));
        $response = $client->send();

        $dataResponse     = json_decode($response->getBody(), true);
        $result->code = isset($dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID']) ? Result::CODE_SUCCESS : 1;
        $result->info = isset($dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID']) ? Result::INFO_SUCCESS : 'Failed';
        $result->data = isset($dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID']) ?  $dataResponse['INCIDENTMboKeySet']['INCIDENT']['TICKETID'] : null;
        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal create ticket.';
        
    }

    $dataActivity = [
        'order_id'  => $order['order_id'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'API',
        'xs2'       => 'HIT API GET TICKET NOSSA',
        'xs3'       => $result->info,
        'xs4'       => json_encode($params,JSON_UNESCAPED_SLASHES),
        'xs5'       => json_encode($dataResponse) ?? null,
        'xs9'       => $data['label'],
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    $dataMilestoneActivity = [
        'order_id'  => $order['order_id'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'MILESTONE',
        'xs2'       => $result->code == Result::CODE_SUCCESS ? 'SUCCESS CREATE TICKET NOSSA' :  'FAILED CREATE TICKET NOSSA' ,
        'xs3'       => $user->code,
        'xs4'       => $result->info,
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataMilestoneActivity);

    return $result;

}

public function sendAttachmentTicket($params,$orderid){
    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);

    try {

        $tokenSIT = $this->getTokenSIT('utonline');
        if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
            throw new \Exception('Gagal upload ticket  . Error : Gagal token',5);
        }                 
        $url      = $naf->getController()->getConfig()['api']['attachmentTicket']['Url'];
       
        $client = new Client();
        $client->setUri($url);

        $client->setHeaders(array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.$tokenSIT['data']['access_token']
        ));

        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
                );
        
        $client->setMethod('POST');
        $client->setRawBody(json_encode($params));
        
        $response = $client->send();

        $dataResponse     = json_decode($response->getBody(), true);

        $result->code = $dataResponse['attachmentTicketResponse']['document']['statusCode'] == '200' ? Result::CODE_SUCCESS : 1;
        $result->info = $dataResponse['attachmentTicketResponse']['document']['statusCode'] == '200' ? Result::INFO_SUCCESS : $dataResponse['attachmentTicketResponse']['document']['returnMessage'] ;
        $result->data = $dataResponse['attachmentTicketResponse']['document']['statusCode'] == '200' ? $dataResponse['attachmentTicketResponse']['document']['returnMessage'] : null;
        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal upload ticket.';
        
    }

    $dataActivity = [
        'order_id'  => $orderid,
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'API',
        'xs2'       => 'HIT API UPLOAD TICKET',
        'xs3'       => $result->info,
        'xs4'       => json_encode($params),
        'xs5'       => json_encode($dataResponse) ?? null
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    $dataMilestoneActivity = [
        'order_id'  => $order['order_id'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'MILESTONE',
        'xs2'       => $result->code == Result::CODE_SUCCESS ? 'SUCCESS ATTACHMENT IMAGE TICKET NOSSA' :  'FAILED ATTACHMENT IMAGE TICKET NOSSA',
        'xs3'       => $user->code,
        'xs4'       => $result->info,
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataMilestoneActivity);

    return $result;
}

public function reopenTicket($data){

    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);

    try {

        $tokenSIT = $this->getTokenSIT('utonline');
        if ($tokenSIT['code'] != Result::CODE_SUCCESS) {
            throw new \Exception('Gagal create tiket  . Error : Gagal token',5);
        }                 
        $url      = $naf->getController()->getConfig()['api']['reopenTicket']['Url'];
        
       
        $params = [
           'updateINCIDENTAPI' => [
                'INCIDENTAPISet' => [
                    'INCIDENT' => [
                        'CLASS' => 'INCIDENT',
                        'TICKETID' => $data['ticketid'],
                        'TK_API' => 'REOPEN'
                    ]
                ]
           ]
        ];

        $client = new Client();
        $client->setUri($url);

        $client->setHeaders(array(
            'Content-Type'  => 'application/json',
            'Authorization' => 'Bearer '.$tokenSIT['data']['access_token'],
            'Accept'        => 'application/json',
        ));

        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
                );
        $client->setMethod('POST');
        $client->setRawBody( json_encode($params,JSON_UNESCAPED_SLASHES));
        $response = $client->send();

        $dataResponse     = json_decode($response->getBody(), true);
       
        $result->code =  isset($dataResponse['UpdateINCIDENTAPIResponse']['UpdateINCIDENTAPIResponse']['@creationDateTime']) ? Result::CODE_SUCCESS : 1;
        $result->info =  isset($dataResponse['UpdateINCIDENTAPIResponse']['UpdateINCIDENTAPIResponse']['@creationDateTime']) ? Result::INFO_SUCCESS : 'Failed';
        $result->data =  isset($dataResponse['UpdateINCIDENTAPIResponse']['UpdateINCIDENTAPIResponse']['@creationDateTime']) ?  null : null;
        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal reopen ticket.';
        
    }

    $dataActivity = [
        'order_id'  => $data['order_id'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'API',
        'xs2'       => 'HIT API REOPEN TICKET',
        'xs3'       => $result->info,
        'xs4'       => json_encode($params,JSON_UNESCAPED_SLASHES),
        'xs5'       => json_encode($dataResponse) ?? null
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    $dataMilestoneActivity = [
        'order_id'  => $data['order_id'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'MILESTONE',
        'xs2'       => $result->code == Result::CODE_SUCCESS ? 'SUCCESS REOPEN TICKET NOSSA' :  'FAILED REOPEN TICKET NOSSA',
        'xs3'       => $user->code,
        'xs4'       => $result->info,
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataMilestoneActivity);

    return $result;


}

public function sendTelegram($data){
    // cari telegram id
    $naf = Entity::get();
    $entity = new \Neuron\Addon\Evidence\UTOnline\Entity\User(
        \Neuron\Addon\Evidence\UTOnline\Entity\User\Storage::factory($naf->getDb(),
        $naf->base()->getModuleConfig())
    );

    if (isset($data['leader']) && $data['leader']) {
        $dataUser['where']['code'] = $data['leader'];
        $getTelegramId = $entity->getUser($dataUser);
    }

    if (isset($data['sto']) && $data['sto']){
        $dataTelegramId = $entity->getTelegramID(['sto' => $data['sto']]);
        $joindataTelegram = "";

        foreach ($dataTelegramId as $valueTelegramId ) {
            $joindataTelegram .= $valueTelegramId['telegram_id'].",";
        }
        $getTelegramId['telegram_id'] = trim($joindataTelegram,",");
    }

   if (isset($getTelegramId['telegram_id']) && $getTelegramId['telegram_id']) {
        // load template code
        $telegramService = new \Neuron\Addon\Evidence\UTOnline\Services\TelegramService;

        // $loadTemplateCode = $telegramService->loadTemplateCode();
        // print_r($loadTemplateCode);die;

        // if ($loadTemplateCode == 1) {
        //     return false;
        // }

        // get template code

       // foreach ($loadTemplateCode->data as $valueItemTemplate) {

           // if ($valueItemTemplate['template_code'] == 'ut_online_01') {
                // send telegram
                $dataSendTelegram = [
                    'template_code' => 'ut_online_01',
                    'subject' => $getTelegramId['telegram_id'],
                    'param' => [
                        'wo' => $data['order_wo'].'/ SC '.$data['scId'],
                        'status' => '<b> Invalid '.$data['role'].'</b> (Tanggal WO:'.$data['tglWO'].')',
                        'keterangan' => $data['keterangan']
                    ]
                ];

                $sendToTelegram = $telegramService->sendTelegram($dataSendTelegram,$data['order_id']);
                
                if ($sendToTelegram == 1) {
                    return false;
                }

                $dataActivity = [
                    'order_id'  => $data['order_id'],
                    'xs1'       => 'MILESTONE',
                    'xs2'       => 'SUKSES KIRIM TELEGRAM',
                    'xs3'       => $data['leader'],
                ];
                $activity = new OrderService;
                $activity->saveOrderActivities($dataActivity);

           // }
           
       // }

        // print_r($loadTemplateCode);die;
   }else{

        $dataActivity = [
            'order_id'  => $data['order_id'],
            'xs1'       => 'MILESTONE',
            'xs2'       => 'GAGAL KIRIM TELEGRAM | TIDAK ADA TELEGRAM ID',
            'xs3'       => $data['leader'],
        ];
        $activity = new OrderService;
        $activity->saveOrderActivities($dataActivity);

   }
   
   return true;
   
}

public function formatKeteranganTelegramPiloting($dataVerifs,$keteranganReturn,$fotoTidakSesuai,$dataLabelfotoNotvalid,$dataOrder,$tlta){

    $ujipetikinvalid = [];
    $dataKeterangan = "".PHP_EOL;
    $increment=0;

    $dataKeterangan .= "<b>Teknisi : </b>".$dataOrder['laborCode'].PHP_EOL;
    $dataKeterangan .= "<b>Agent TL TA  :</b> ".$tlta.PHP_EOL;
    
    if ($fotoTidakSesuai == 1 ) {

        $increment++;
        $dataKeterangan .= '<b>'. $increment.'. Foto tidak sesuai dengan label  : </b>'.PHP_EOL;
        foreach ($dataLabelfotoNotvalid as $valueLabelNotvalid) {
            $dataKeterangan  .= '- '.$valueLabelNotvalid->xs6 .' : '.$valueLabelNotvalid->xs4.PHP_EOL; 
        }
        
    }

    $splitKeterangan = explode(",",$keteranganReturn);
   
   
    if ($splitKeterangan[0] != '-'){ // cek spek invalid
        $increment++;
        $dataKeterangan .= '<b>'.$increment.'. Hasil Ukur spec tidak sesuai</b> : '.$splitKeterangan[0].'.'.PHP_EOL;
    }

    if ($splitKeterangan[1] != '-') { // Material invalid
        $increment++;
        $dataKeterangan .= '<b>'.$increment.'. Data Material tidak sesuai</b> : '.$splitKeterangan[1].'.'.PHP_EOL;
    }

    if ($splitKeterangan[2] != '-') { // BA invalid
        $increment++;
        $dataKeterangan .= '<b>'.$increment.'. BA tidak sesuai</b> : '.$splitKeterangan[2].'.'.PHP_EOL;
    }

    return $dataKeterangan;

}

public function formatKeteranganTelegramNonPiloting($dataNotValid,$keteranganReturn,$dataLabelfotoNotvalid,$dataOrder){

    $keteranganNotValid = "".PHP_EOL;
    $increment = 0;

    if ($dataNotValid['materialTidakSesuai']) {
        $increment++;
        $keteranganNotValid .= '<b>'.$increment.'. Data Material tidak sesuai</b>'.PHP_EOL;
    }

    if ($dataNotValid['fotoTidakSesuai']) {
        $increment++;
        $keteranganNotValid .='<b>'.$increment.'. Foto tidak sesuai dengan label</b>:'.PHP_EOL;
        foreach ($dataLabelfotoNotvalid as $valueLabelNotvalid) {
            $keteranganNotValid  .= '- '.$valueLabelNotvalid->xs6 .' : '.$valueLabelNotvalid->xs4.PHP_EOL; 
        }
    }

    if ($dataNotValid['baTidakSesuai']) {
        $increment++;
        $keteranganNotValid .='<b>'.$increment.'. BA tidak sesuai </b>'.PHP_EOL;
    }

    if ($dataNotValid['hasilUkurKosong']) {
        $increment++;
        $keteranganNotValid .='<b>'.$increment.'. Hasil Ukur spec tidak sesuai</b>'.PHP_EOL;
    }

    if ($dataNotValid['tanggalTidakSesuai']) {
        $increment++;
        $keteranganNotValid .='<b>'.$increment.'. Tanggal tidak sesuai</b>'.PHP_EOL;
    }

    if ($dataNotValid['koordinatTidakSesuai']) {
        $increment++;
        $keteranganNotValid .='<b>'.$increment.'. koordinat tidak sesuai</b>'.PHP_EOL;
    }

    return $keteranganNotValid.'<b>Keterangan : </b>'.$keteranganReturn;

}

public function getImageQc1($data){


    $result = new Result;
    $naf = Entity::get();
    $user = $naf->user(true);

    try {
     
        $url      = $naf->getController()->getConfig()['api']['getImageQc1']['Url'];
        $username = $naf->getController()->getConfig()['api']['getImageQc1']['Username'];
        $password = $naf->getController()->getConfig()['api']['getImageQc1']['Password'];

        $request = new Request();
        $request->getHeaders()->addHeaders(array(

            'Content-Type' => 'application/x-www-form-urlencoded; charset=UTF-8'
            
        ));
       
        $arrParam = array(
            "trackId" => $data['trackid']
        );
        
        $jsonParam = json_encode($arrParam);

        $request->setUri($url);
        $request->setMethod('POST');
        $arraydata = array(

            'data'  =>  $jsonParam
            
        );
       
        $request->setPost(new Parameters($arraydata));
        

        $client = new Client();

        $client->setAuth($username, $password, \Zend\Http\Client::AUTH_BASIC);
        $client->setOptions(
                    array('timeout'=>120,
                        'persistent' => false,
                        'sslverifypeer' => false,
                        'sslallowselfsigned' => false,
                        'sslusecontext' => false,
                        'ssl' => array(
                            'verify_peer' => false,
                            'allow_self_signed' => true,
                            'capture_peer_cert' => true,
                        ),
                    )
            );
      
        $response = $client->dispatch($request);
        
        $dataResponse = json_decode($response->getBody(), true);

        $result->code = $dataResponse['code'];
        $result->info = $dataResponse['info'];
        $result->data = 'data:image/jpg;base64,'.$dataResponse['data'];


        
    } catch (\Exception $ex) {

        $result->code = $ex->getCode() == 5 ? 1 : 2;
        $result->info = $ex->getCode() == 5 ? $ex->getMessage() :'Gagal get info image';
        
    }

    $dataActivity = [
        'order_id'  => $data['orderId'],
        'xn2'       => $user->id ?? 1,
        'xn3'       => $result->code == Result::CODE_SUCCESS ? '1' : '0',
        'xs1'       => 'API',
        'xs2'       => 'HIT API GET FOTO RUMAH QC1',
        'xs3'       => $result->info,
        'xs4'       => $arraydata,
        'xs5'       => $result->code == 0 ? null : $dataResponse
    ];

    $activity = new OrderService;
    $activity->saveOrderActivities($dataActivity);

    return $result;

}

public function saveInvalidSymptom($order, $dataVerifs)
{
   
    $naf = Entity::get();

    $dataInput = array();
    foreach ($dataVerifs as $key => $verif) {
        if (isset($verif['symptomInvalid']) && $verif['symptomInvalid']) {
            foreach ($verif['symptomInvalid'] as $itemSymptomInavlid) {
                $dataInput[] = [
                    'order_id'              => $order['order_id'],
                    'order_attribute_id'    => $verif['xn1'],
                    'code'                  => $itemSymptomInavlid['id'],
                    'label'                 => $itemSymptomInavlid['label']
                ];
            }
        }
    }

  //  print_r($dataInput);die;

    $symptomInvalid = new \Neuron\Addon\Evidence\UTOnline\Entity\OrderSymptomInvalid(\Neuron\Addon\Evidence\UTOnline\Entity\OrderSymptomInvalid\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
    if (!empty($dataInput)) {
        //Cleansing Exist Data
        $symptomInvalid->delete($order['order_id'], OrderSymptomInvalid\Storage::DELETEBY_ORDERID);

        foreach ($dataInput as $ujp) {
            $saveInsert = $symptomInvalid->saveEx($ujp, \Neuron\Order\Detail\Storage::SAVE_INSERT);
        }
    }

    return true;
}

public function getSymtompInvalid($evidence)
{
    $naf = Entity::get();

    $ujiPetik = new OrderSymptomInvalid(OrderSymptomInvalid\Storage::factory($naf->getDb(), $naf->getController()->getConfig()));
    $checkUjp = $ujiPetik->loadList($evidence['attributeId'], OrderSymptomInvalid\Storage::LOADBY_ATTR_ID);
   
    return $checkUjp->data ?? null;
}

public function getEvidenceToSendTicket($dataOrder,$attributeId,$ticketId){

    $minio = new \Neuron\Addon\Evidence\UTOnline\Minio;

    $find = AttributesMap::searchByEvidence($dataOrder['attributes'], $attributeId, $dataOrder['order_id'], 7);
                   
    if ($find) {

        $base64image = $minio->loadImageAws($find['path'],false);
        $dataDoclinks["DOCTYPE"] = "Attachments";
        $dataDoclinks["URLNAME"] = $ticketId."INVALID_EVIDENCE.jpg";
        $dataDoclinks["URLTYPE"] = "FILE";
        $dataDoclinks["PRINTTHRULINK"] = "0";
        $dataDoclinks["DESCRIPTION"] = $ticketId." tidak valid uji petik ".$itemRetryTicket['label'];
        $dataDoclinks["DOCUMENT"] = "EVIDENCE_BEFORE";
        $dataDoclinks["DOCUMENTDATA"] = str_replace("data:image/jpg;base64,","",$base64image);

    }    

    $regionalMap = [
        'REGIONAL_1' => 'REG-1',
        'REGIONAL_2' => 'REG-2',
        'REGIONAL_3' => 'REG-3',
        'REGIONAL_4' => 'REG-4',
        'REGIONAL_5' => 'REG-5',
        'REGIONAL_6' => 'REG-6',
        'REGIONAL_7' => 'REG-7'
    ];

    $dataAttachment = [
        "SITEID" => $regionalMap[$dataOrder['regional']],
        "TICKETID" => $sendTicket->data,
        "CLASS" => "INCIDENT",
        "DOCLINKS" => $dataDoclinks,
    ];
    
    return $dataAttachment;

}

public function updateStatusTicket($dataUpdateTicket){

    $messageTicket = [];
   

    if (isset($dataUpdateTicket['resultCreateTicket']) && $dataUpdateTicket['resultCreateTicket'] ) {
        $statusTicket = $dataUpdateTicket['resultCreateTicket'] == 0 ? ($dataUpdateTicket['resultAttachmentTicket'] == 0 ? 1 : 5) : 3;
    }else if(isset($dataUpdateTicket['resultReopenTicket']) && $dataUpdateTicket['resultReopenTicket'] ){
        $statusTicket = $dataUpdateTicket['resultReopenTicket'] == 0 ? 1 : 4; 
    }else {
        $statusTicket = $dataUpdateTicket['resultAttachmentTicket'] == 0 ? 1 : 5;
    }

    $dataUpdate['order_detail_id'] =  $dataUpdateTicket['detail_id'];
    $messageStatus = $statusTicket == 1 ? 'Open' : ($statusTicket == 3 ? 'Falied Create Ticket' : ($statusTicket == 5 ? 'Failed Upload attcahment ticket' : '-'));
                    
    if ($dataUpdateTicket['flag'] == 'ODP_TIDAK_BERSIH') {
        $dataUpdate['xn1'] = $statusTicket;
        $dataUpdate['xs1'] = $dataUpdateTicket['ticket_id'];
        $dataUpdate['xs2'] = $dataUpdateTicket['label'];
        $dataUpdate['xs3'] =  $dataUpdateTicket['attribute_id'];
        $messageTicket = [
            'ticket_id' => $dataUpdateTicket['ticket_id'],
            'status'    => $messageStatus, 
        ];
    }
    
    if ($dataUpdateTicket['flag']  == 'ODP_GENDONG' ) {
        $dataUpdate['xn2'] = $statusTicket;
        $dataUpdate['xs4'] = $dataUpdateTicket['ticket_id'];
        $dataUpdate['xs5'] = $dataUpdateTicket['label'];
        $dataUpdate['xs6'] = $dataUpdateTicket['attribute_id'];
        $messageTicket = [
            'ticket_id' => $dataUpdateTicket['ticket_id'],
            'status'    => $messageStatus, 
        ];
    }
    
    if ($dataUpdateTicket['flag'] == 'ODP_TIDAK_ADA' ) {
        $dataUpdate['xn3'] = $statusTicket;
        $dataUpdate['xs7'] = $dataUpdateTicket['ticket_id'];
        $dataUpdate['xs8'] = $dataUpdateTicket['label'];
        $dataUpdate['xs9'] = $dataUpdateTicket['attribute_id'];
        $messageTicket = [
            'ticket_id' => $dataUpdateTicket['ticket_id'],
            'status'    => $messageStatus, 
        ];
    }

    if ($dataUpdateTicket['flag'] == 'ODP_PINTU_HILANG' ) {
        $dataUpdate['xn5'] = $statusTicket;
        $dataUpdate['xs10'] = $dataUpdateTicket['ticket_id'];
        $dataUpdate['xs11'] = $dataUpdateTicket['label'];
        $dataUpdate['xs12'] = $dataUpdateTicket['attribute_id'];
        $messageTicket = [
            'ticket_id' => $dataUpdateTicket['ticket_id'],
            'status'    => $messageStatus, 
        ];
    }


    $detailService = new \Neuron\Addon\Evidence\UTOnline\Services\OrderDetailService;
    $detailService->saveDetails($dataUpdate);

    return $messageTicket;
}



    

