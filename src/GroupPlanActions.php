<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class GroupPlanActions extends Service
{
    public function execute(string $action,array $input): array
    {
        $approvals = new PlantConfirmations($this->db,$this->scope);
        $key = WebInput::stringParameter($input,'request_key');
        $result = [];
        $target = WebView::url('plant-inbox');
        if ($action==='authorize-site-signer') {
            $approvals->authorize(WebInput::id($input,'site'),WebInput::id($input,'user'),WebInput::integer($input['enabled'] ?? null,'授权',0,1)===1);
        } elseif ($action==='decide-network-line') {
            $result = $approvals->decide($key,WebInput::id($input,'line'),WebInput::id($input,'site'),
                WebInput::integer($input['previous'] ?? null,'上次签认编号',0),WebInput::id($input,'department'),
                WebInput::integer($input['accepted'] ?? null,'产能确认',0,1)===1,WebInput::stringParameter($input,'promised_on'),WebInput::stringParameter($input,'reason'));
        } else {
            $ids = $input['selected_line_ids'] ?? null;
            Value::assert(is_array($ids),'selected_line_ids 须为编号列表。',422,'selected_line_ids');
            $id = WebInput::id($input,'scenario');
            $revision = WebInput::integer($input['revision'] ?? null,'revision');
            $target = WebView::url('scenarios',$id);
            $result = $action==='submit-network-plan' ? $approvals->submit($key,$id,$revision,$ids)
                : (new GroupPublication($this->db,$this->scope))->publish($key,$id,$revision,$ids,WebInput::stringParameter($input,'horizon_end'));
        }
        $details = [];
        foreach ($result['blocked_lines'] ?? [] as $row) {
            $details[] = '建议 '.$row['line_id'].' 待处理：'.($row['reason'] ?? implode('，',array_column($row['sites'],'reason')));
        }
        return ['target'=>$target,'result'=>$result+['url'=>$target],'message'=>'处理结果已记录。','details'=>$details];
    }
}
