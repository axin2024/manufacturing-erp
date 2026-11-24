<?php

declare(strict_types=1);

namespace ManufacturingErp;

final class DemoScenario
{
    public const EMAIL = 'admin@factory.example.test';
    public const AS_OF = '2037-06-01';
    public const PRODUCTION = '2037-06-12';
    public const QUALITY = '2037-06-13';
    public const DELIVERY_DUE = '2037-06-15';

    public function __construct(private readonly Database $db)
    {
    }

    public function install(string $email, string $password): array
    {
        $email = strtolower(trim($email));
        $identity = new Identity($this->db);
        return $this->db->transaction(function () use ($identity, $email, $password): array {
            $account = $this->db->row('SELECT id FROM users WHERE email=?', [$email]);
            if ($account !== null) {
                $user = $identity->authenticate($email, $password);
                foreach ($identity->organizations($user) as $organization) {
                    $stored = $this->lookup($identity->scope($user, (int) $organization['id']));
                    if ($stored !== null) {
                        return $stored;
                    }
                }
                throw new BusinessError('该邮箱已经用于其他账套，请使用单独的演示账号。', 409);
            }
            $ids = $identity->initialize('澄岳精工集团 · 合成演示', 'DEMO-FACTORY', '澄岳精工制造', 'CNY', '演示管理员', $email, $password);
            $scope = $identity->scope($ids['user'], $ids['org']);
            $result = $this->run($scope);
            $this->db->run('INSERT INTO demo_cases (org_id,version,result) VALUES (?,1,?)', [$scope->org, Value::json($result)]);
            return $result;
        });
    }

    public function lookup(Scope $scope): ?array
    {
        if (!$scope->can('admin')) {
            return null;
        }
        $record = $this->db->row('SELECT result FROM demo_cases WHERE org_id=?', [$scope->org]);
        return $record === null ? null : Value::fromJson($record['result']);
    }

    private function run(Scope $scope): array
    {
        $master = new MasterData($this->db, $scope);
        $engineering = new ProductEngineering($this->db, $scope);
        $inventory = new Inventory($this->db, $scope);
        $purchase = new Purchasing($this->db, $scope);
        $sales = new Sales($this->db, $scope);
        $planning = new Planning($this->db, $scope);
        $quality = new Inspection($this->db, $scope);
        $shipping = new Shipping($this->db, $scope);

        $trade = $master->createOrganization('DEMO-TRADE', '澄岳贸易', 'CNY');
        $raw = $master->addLocation('RAW', '原料仓');
        $finished = $master->addLocation('FG', '成品检验与发运');
        $customer = $master->addParty('C-PRECISION', '合成客户 · 海川设备', 'customer');
        $supplier = $master->addParty('V-FASTENER', '合成供应商 · 北辰紧固件', 'supplier');
        $device = $master->addItem('DEVICE', '台式装配设备', '台', 'manufacture', 3000, 3);
        $pin = $master->addItem('PIN', '标准定位销', '件', 'purchase', 1200, 1);
        $alternative = $master->addItem('PIN-R', '加强定位销', '件', 'purchase', 1400, 1);
        $module = $master->addItem('MODULE', '支撑组件', '件', 'manufacture', 600, 1);
        $blank = $master->addItem('BLANK', '支撑件毛坯', '件', 'purchase', 500, 1);
        $moduleBom = $engineering->publishBom($module, self::AS_OF, [['item' => $blank, 'quantity' => 1000, 'uom' => '件']]);
        $deviceBom = $engineering->publishBom($device, self::AS_OF, [
            ['item' => $pin, 'quantity' => 2000, 'uom' => '件', 'alternatives' => [$alternative]],
            ['item' => $module, 'quantity' => 1000, 'uom' => '件'],
        ]);
        $machines = $this->resources($scope, $master, $engineering, $device);

        $standardLot = $inventory->openingBalance('demo-opening-pins', $pin, $raw, 'PIN-OPEN-70', '件', 70_000, 1200, self::AS_OF)['lot_id'];
        $replaceLot = $inventory->openingBalance('demo-opening-replacement', $alternative, $raw,
            'PIN-R-10', '件', 10_000, 1400, self::AS_OF)['lot_id'];
        $moduleLot = $inventory->openingBalance('demo-opening-module', $module, $raw, 'MODULE-100', '件', 100_000, 600, self::AS_OF)['lot_id'];

        $preexisting = $purchase->create('demo-incoming', 'PO-INCOMING-30', $supplier, self::AS_OF, [
            ['item' => $pin, 'uom' => '件', 'quantity' => 30_000, 'unit_price_minor' => 1200, 'due_on' => '2037-06-10'],
        ]);
        $purchase->issue($preexisting['order_id']);
        $sale = $sales->create('demo-sale', 'SO-DEVICE-100', $customer, [
            ['item' => $device, 'quantity' => 100_000, 'unit_price_minor' => 6500,
                'deliveries' => [['due_on' => self::DELIVERY_DUE, 'quantity' => 100_000]]],
        ]);
        $sales->freeze($sale['order_id']);
        $plan = $planning->calculate('demo-plan', self::AS_OF, self::DELIVERY_DUE, Planning::POLICY)['run_id'];
        $requirements = array_column($planning->run($plan)['requirements'], null, 'item_id');
        $short = $requirements[$pin];
        $planning->approve($plan);
        $plannedOrder = $this->sourceOrder($scope, $purchase, $pin, $supplier, $short);
        $existingReceipt = $this->receive($purchase, $quality, $preexisting, 'RC-INCOMING', 'PIN-INCOMING-30', $raw, 30_000, '2037-06-10');
        $plannedReceipt = $this->receive($purchase, $quality, $plannedOrder, 'RC-NET-SHORTAGE', 'PIN-ORDERED-100', $raw, (int) $short['shortage_milli'], '2037-06-11');
        $finance = new ReceivablesPayables($this->db, $scope);
        $bill = $finance->bill('demo-bill', $supplier, 'AP-PIN-INVOICE', '2037-06-11', '2037-07-11', [
            ['receipt_line_id' => $existingReceipt['receipt_line_id'], 'amount_minor' => $existingReceipt['amount_minor']],
            ['receipt_line_id' => $plannedReceipt['receipt_line_id'], 'amount_minor' => $plannedReceipt['amount_minor']],
        ]);

        $work = new WorkOrders($this->db, $scope);
        $workId = $work->create('demo-work', 'WO-DEVICE-100', $device, 100_000, self::AS_OF, self::PRODUCTION, self::DELIVERY_DUE,
            (int) $requirements[$device]['proposal_id'])['work_id'];
        $work->approve($workId);
        $record = $work->details($workId);
        $this->schedule($scope, $work, $record, $machines);
        $inputs = $this->issueMaterials($scope, $record, $pin, $module, $alternative, $raw,
            [$standardLot, $existingReceipt['lot_id'], $plannedReceipt['lot_id'], $replaceLot, $moduleLot]);
        $output = (new Completion($this->db, $scope))->report('demo-completion', $workId, $finished, 'DEVICE-FINISHED-100',
            100_000, self::PRODUCTION, $inputs['usage']);
        $decision = $quality->decide('demo-final-qc', $output['inspection_id'], 1, 98_000, 0, 2000, self::QUALITY,
            '98 台检验合格，2 台定位调整后复检。');
        $inspection = $quality->details($output['inspection_id']);
        $reservation = $shipping->reserve('demo-reserve', $sale['demand_ids'][0], $output['lot_id'], $finished, (int) $inspection['accepted_milli']);
        $shipment = $shipping->dispatch('demo-ship', 'SHIP-DEVICE-98', $customer, self::QUALITY, [
            ['reservation_id' => $reservation['reservation_id'], 'quantity' => $reservation['qty_milli']],
        ]);
        $latest = $sales->order($sale['order_id'])['demands'][0];
        $recall = (new Genealogy($this->db, $scope))->trace($replaceLot, 'downstream');
        $materialVariance = array_column((new WorkCosts($this->db, $scope))->report($workId)['summary'], 'variance_minor', 'element');
        return [
            'schema_version' => 1, 'org_id' => $scope->org, 'trade_org_id' => $trade, 'as_of' => self::AS_OF,
            'objects' => [
                'sales_order_id' => $sale['order_id'], 'demand_id' => $sale['demand_ids'][0],
                'device_bom_id' => $deviceBom, 'module_bom_id' => $moduleBom,
                'planning_run_id' => $plan, 'pin_item_id' => $pin, 'pin_requirement_ref' => $short['ref'],
                'incoming_order_id' => $preexisting['order_id'], 'planned_order_id' => $plannedOrder['order_id'],
                'planned_receipt_id' => $plannedReceipt['receipt_id'], 'work_id' => $workId, 'substitute_issue_id' => $inputs['replacement_id'],
                'substitute_lot_id' => $replaceLot, 'finished_lot_id' => $output['lot_id'], 'inspection_id' => $output['inspection_id'],
                'rework_id' => $decision['rework_order_id'], 'shipment_id' => $shipment['shipment_id'],
                'receivable_id' => $shipment['receivable_id'], 'payable_id' => $bill['payable_id'],
            ],
            'facts' => [
                'pin_gross_milli' => (int) $short['gross_milli'], 'pin_stock_milli' => (int) $short['stock_milli'],
                'pin_incoming_milli' => (int) $short['incoming_milli'], 'pin_shortage_milli' => (int) $short['shortage_milli'],
                'material_variance_minor' => (int) $materialVariance['material'],
                'reported_cost_minor' => $output['actual_minor'], 'finished_milli' => $output['qty_milli'],
                'accepted_milli' => (int) $inspection['accepted_milli'], 'rework_milli' => (int) $inspection['rework_milli'],
                'shipped_milli' => $shipment['lines'][0]['qty_milli'], 'shipped_cost_minor' => $shipment['lines'][0]['cogs_minor'],
                'outstanding_milli' => (int) $latest['qty_milli'] - (int) $latest['dispatched_milli'],
                'affected_customer' => $recall['shipments'][0]['customer'],
            ],
        ];
    }

    private function resources(Scope $scope, MasterData $master, ProductEngineering $engineering, int $device): array
    {
        $assembly = $master->createDepartment('ASSEMBLY', '装配车间');
        $testing = $master->createDepartment('TESTING', '检测车间');
        $center = $engineering->addCenter($assembly, 'ASSEMBLY', '总装工位');
        $testCenter = $engineering->addCenter($testing, 'TESTING', '负载检测工位');
        $scheduling = new Scheduling($this->db, $scope);
        foreach ([$center, $testCenter] as $id) {
            $scheduling->addShift($id, self::PRODUCTION . ' 08:00:00', self::PRODUCTION . ' 17:00:00');
            $scheduling->addShift($id, self::QUALITY . ' 08:00:00', self::QUALITY . ' 17:00:00');
        }
        $engineering->publishRoute($device, self::AS_OF, [
            ['center' => $center, 'label' => '设备总装', 'setup_minutes' => 10, 'run_seconds_per_unit' => 6],
            ['center' => $testCenter, 'label' => '装配后负载测试', 'setup_minutes' => 5, 'run_seconds_per_unit' => 6],
        ]);
        return [
            $scheduling->addMachine($center, 'ASSEMBLY-1', '总装台 A'),
            $scheduling->addMachine($testCenter, 'TEST-1', '测试台 A'),
        ];
    }

    private function sourceOrder(Scope $scope, Purchasing $purchase, int $pin, int $supplier, array $short): array
    {
        $qty = (int) $short['shortage_milli'];
        $proposal = (int) $short['proposal_id'];
        $due = $short['needed_on'];
        $sourcing = new Sourcing($this->db, $scope);
        $request = $sourcing->request('demo-rfq', 'RFQ-PIN-SHORTAGE', $pin, $qty, $due, $proposal)['request_id'];
        $quote = $sourcing->quote('demo-quote', $request, $supplier, 'BID-PIN', 1200, '2037-06-11', $due)['quote_id'];
        $sourcing->award($request, $quote, '2037-06-02');
        $order = $purchase->create('demo-shortage-order', 'PO-PIN-SHORTAGE-100', $supplier, '2037-06-02', [
            ['item' => $pin, 'uom' => '件', 'quantity' => $qty, 'unit_price_minor' => 1200, 'due_on' => $due,
                'proposal_id' => $proposal, 'quote_id' => $quote],
        ]);
        $purchase->issue($order['order_id']);
        return $order;
    }

    private function receive(Purchasing $purchase, Inspection $quality, array $order, string $code, string $lot, int $raw, int $qty, string $day): array
    {
        $receipt = $purchase->receive($code, $order['order_id'], $code, $day, [
            ['line_id' => $order['line_ids'][0], 'lot' => $lot, 'location' => $raw, 'quantity' => $qty],
        ]);
        $line = $receipt['lines'][0];
        $quality->decide('demo-qc-' . $code, $line['inspection_id'], 1, $qty, 0, 0, $day, '进料质量检查合格。');
        return $line + ['receipt_id' => $receipt['receipt_id']];
    }

    private function schedule(Scope $scope, WorkOrders $work, array $record, array $machines): void
    {
        $scheduling = new Scheduling($this->db, $scope);
        $execution = new OperationExecution($this->db, $scope);
        $first = (int) $record['operations'][0]['id'];
        $second = (int) $record['operations'][1]['id'];
        $scheduling->book('demo-slot-assembly', $first, $machines[0], self::PRODUCTION . ' 08:00:00', self::PRODUCTION . ' 09:00:00');
        $scheduling->book('demo-slot-test', $second, $machines[1], self::PRODUCTION . ' 09:00:00', self::PRODUCTION . ' 10:00:00');
        $work->release((int) $record['id']);
        $execution->report('demo-actual-assembly', $first, self::PRODUCTION . ' 08:00:00', self::PRODUCTION . ' 08:30:00', 3600, 2400);
        $execution->report('demo-actual-test', $second, self::PRODUCTION . ' 09:00:00', self::PRODUCTION . ' 09:30:00', 2400, 1200);
    }

    private function issueMaterials(Scope $scope, array $record, int $pin, int $module, int $alternative, int $raw, array $lots): array
    {
        $byItem = array_column($record['materials'], 'id', 'item_id');
        $material = (int) $byItem[$pin];
        $service = new WorkMaterials($this->db, $scope);
        $approval = $service->authorize('demo-substitute', $material, 'substitute', $alternative, 10_000,
            '按批准的规格替用 10 件加强定位销。')['authorization_id'];
        $usage = [];
        foreach ([70_000, 30_000, 90_000, 10_000, 100_000] as $index => $quantity) {
            $line = $index === 4 ? (int) $byItem[$module] : $material;
            $issue = $service->issue('demo-issue-' . $index, $line, $lots[$index], $raw, $quantity, self::PRODUCTION,
                $index === 3 ? $approval : null);
            $usage[] = ['issue_id' => $issue['issue_id'], 'quantity' => $quantity];
        }
        return ['usage' => $usage, 'replacement_id' => $usage[3]['issue_id']];
    }
}
