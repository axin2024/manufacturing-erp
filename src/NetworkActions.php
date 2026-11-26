<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class NetworkActions extends Service
{
    public function execute(string $action, array $input): array
    {
        $network = new FactoryNetwork($this->db, $this->scope);
        $forecast = new WeeklyForecast($this->db, $this->scope);
        $key = WebInput::stringParameter($input, 'request_key');
        $target = WebView::url('network');
        $result = [];
        switch ($action) {
            case 'qualify-transfer-item':
                $result = $network->qualify($key,WebInput::id($input,'lane'),WebInput::id($input,'source_item'),WebInput::id($input,'item'),
                    WebInput::integer($input['enabled'] ?? null,'资格状态',0,1)===1,WebInput::integer($input['revision'] ?? null,'资格版本',0),
                    WebInput::stringParameter($input,'evidence'));
                break;
            case 'change-network-pilot':
                $result = (new NetworkPilot($this->db,$this->scope))->change($key,WebInput::integer($input['revision'] ?? null,'试点版本',0),
                    WebInput::stringParameter($input,'state'));
                $target = WebView::url('network-ownership');
                break;
            case 'freeze-network-plan':
                $plans = new GroupScenarios($this->db, $this->scope);
                $parent = WebInput::optionalId($input,'parent');
                if ($parent !== null) {
                    $original = $plans->detail($parent);
                    [$sites,$start,$end] = [$original['sites'],$original['as_of'],$original['horizon_end']];
                } else {
                    $sites = $input['sites'] ?? null;
                    Value::assert(is_array($sites), '请选择工厂。');
                    $start = WebInput::stringParameter($input,'start');
                    $end = WebInput::stringParameter($input,'end');
                }
                $result = $plans->freeze($key,$sites,$start,$end,$parent,WebInput::integer($input['refresh'] ?? 0,'重新读取供需',0,1) === 1);
                $target = WebView::url('scenarios',$result['scenario_id']);
                break;
            case 'assign-site-ownership':
                (new FactoryOwnership($this->db, $this->scope))->assign(WebInput::stringParameter($input, 'kind'),
                    WebInput::id($input, 'entity'), WebInput::id($input, 'site'));
                $target = WebView::url('network-ownership');
                break;
            case 'backfill-site-ownership':
                $result = (new FactoryOwnership($this->db, $this->scope))->backfill($key, WebInput::stringParameter($input, 'kind'),
                    WebInput::integer($input['limit'] ?? null, '单批数量', 1, 500));
                $target = WebView::url('network-ownership');
                break;
            case 'create-network-site':
                $result = $network->createSite($key, WebInput::stringParameter($input, 'code'), WebInput::stringParameter($input, 'name'));
                break;
            case 'map-site-item':
                $network->mapItem(WebInput::id($input, 'site'), WebInput::id($input, 'item'));
                break;
            case 'set-site-calendar':
                $network->setCalendar(WebInput::id($input, 'site'), WebInput::stringParameter($input, 'date'),
                    WebInput::integer($input['working'] ?? null, '工作日', 0, 1) === 1);
                break;
            case 'save-network-lane':
                $result = $network->saveLane($key, WebInput::id($input, 'source'), WebInput::id($input, 'destination'),
                    WebInput::integer($input['days'] ?? null, '运输工作日', 0, 365), WebInput::integer($input['revision'] ?? null, '版本', 0));
                break;
            case 'assign-demand-site':
                $forecast->assignDemand(WebInput::id($input, 'demand'), WebInput::id($input, 'site'));
                $target = WebView::url('forecasts');
                break;
            case 'import-weekly-forecast':
                $this->requireRole('planner');
                $csv = WebInput::stringParameter($input, 'csv');
                if (isset($_FILES['forecast_file'])) {
                    $file = $_FILES['forecast_file'];
                    Value::assert($file['error'] === UPLOAD_ERR_OK && is_uploaded_file($file['tmp_name']), '预测文件上传失败。');
                    $csv = file_get_contents($file['tmp_name'], false, null, 0, 4_194_305);
                    Value::assert(is_string($csv), '读取上传文件失败。');
                }
                $result = $forecast->import($key, WebInput::stringParameter($input, 'batch'), $csv);
                $target = WebView::url('forecasts');
                break;
        }
        return ['target' => $target, 'message' => '已保存。', 'result' => $result + ['url' => $target]];
    }
}
