<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class TransitActions extends Service
{
    public function execute(string $action,array $input): array
    {
        $service = new NetworkTransit($this->db,$this->scope);
        if ($action==='grant-warehouse-site') {
            $service->authorize(WebInput::id($input,'site'),WebInput::id($input,'user'),
                WebInput::integer($input['enabled'] ?? null,'授权状态',0,1)===1);
            return ['target'=>WebView::url('transit'),'message'=>'仓储授权已更新。','result'=>[]];
        }
        $key = WebInput::stringParameter($input,'request_key');
        $order = WebInput::id($input,'transfer');
        $reference = WebInput::stringParameter($input,'reference');
        $date = WebInput::stringParameter($input,'posted_on');
        $result = match ($action) {
            'clear-transfer' => (new TransferRegister($this->db,$this->scope))->clear($key,$order,$date,WebInput::stringParameter($input,'evidence')),
            'record-transfer-loss' => $service->recordLoss($key,$order,WebInput::id($input,'variance'),WebInput::quantity($input,'quantity'),
                $reference,$date,WebInput::stringParameter($input,'evidence')),
            'dispatch-transfer' => $service->ship($key,$order,$reference,WebInput::id($input,'lot'),WebInput::id($input,'location'),
                WebInput::quantity($input,'quantity'),$date),
            'receive-transfer' => $service->receive($key,$order,WebInput::id($input,'shipment'),$reference,WebInput::id($input,'location'),
                WebInput::quantity($input,'expected'),WebInput::quantity($input,'counted'),$date,
                WebInput::stringParameter($input,'reason'),WebInput::optionalId($input,'variance')),
            'return-transfer-excess' => $service->returnExcess($key,$order,WebInput::id($input,'variance'),WebInput::quantity($input,'quantity'),
                WebInput::stringParameter($input,'evidence')),
        };
        $url = WebView::url('transit',$order);
        return ['target'=>$url,'message'=>'已登记并更新在途台账。','result'=>$result+['url'=>$url]];
    }
}
