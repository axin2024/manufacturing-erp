# 制造与贸易集团 ERP｜MySQL 数据与集成

## 持久化边界

MySQL 8.4 InnoDB 是本项目业务状态与授权关系的权威存储。租户身份进入业务唯一键与关联检查。媒体、文件、备份或模型大对象允许使用文件或对象存储，库内保存版本化引用、长度、摘要及所有者。派生缓存可从持久记录重新构建。

## 逻辑实体与事实来源

| 业务域 | 核心实体与必要字段 | 写入时必须维持的规则 |
|---|---|---|
| 组织与主数据 | org(id,parent,ledger), item(code,uom), conversion(item,rev,factor) | 物料有基础单位与组织策略，单位换算固定在交易行，法人间移动须成对生成往来。 |
| 销售与承诺 | sales_line(order,item,qty,due), demand(line,version,status) | 订单分批交货，每一行保留需求日期、已分配数量和计划来源。 |
| BOM 与工艺 | bom_rev(product,rev,effective), component(rev,item,factor), operation(route,seq) | 生效日选择物料与工序版本，发布前校验环、损耗与单位；投产工单持有版本快照。 |
| 物料计划 | mrp_run(org,cutoff), pegging(run,demand,supply,qty) | 按日期扣除可用和按期在途，冻结已确认供给，重算保存差异供计划员接受。 |
| 采购与仓储 | po_line(plan,item,qty), receipt(line,lot,quality), stock_move(lot,source,qty) | 采购行关联短缺建议；合格、拒收和待检分账；批次移动生成流水。 |
| 车间与委外 | work_order(bom_rev,route,state), issue(work,lot,qty), subcontract_balance(vendor,item) | 设备班次限制排程，领用替代料需批准，委外供料跟踪加工方结存与损耗。 |
| 质检与交货 | inspection(work,accepted,rework), genealogy(input_lot,output_lot), dispatch(line,lot) | 合格数量进入成品库，返工件与新完工分别跟踪；交付批次可回溯到投入批次。 |
| 成本与业财 | cost_entry(work,element,minor), open_item(party,due,balance), journal(org,period,source) | 归集领料、人工、制造费用与委外费，凭证分币种平衡；锁期后追加调整。 |

## 事务、索引与对账

- **身份与约束**　物料编码在组织内唯一，BOM 版本限定生效区间，收货行与源单行和批次关联。
- **业务索引**　按需求日和供给可用日扫描 MRP，按投入批次反查成品谱系，按工单与成本要素核对。
- **数据生命周期**　保留已关期成本快照与工艺来源，撤销计划不得删除已领用的库存流水。

过账锁定期间与批次余额，领退料、工单用量和成本来源关联在同一事务提交；期间关闭后以调整凭证更正。

列表使用租户、业务状态、更新时间和稳定主键分页。外部事件保留来源、外部号、内容摘要、处理结果和业务对象引用。同键不同内容产生显式冲突；网络超时后按原键查单。跨存储发布应先确认文件持久，再发布其数据库引用；回收须验证没有有效引用。

## 集成契约

导入物料、BOM 与期初库存；导出领料单、批次谱系、成本差异、应收应付与凭证清单。

运行 MRP 输入组织、需求截点及策略版本；输出供需关联、短缺清单和建议来源。

领料提交工单、批次、实耗数量；返回库存流水与成本项，完工交付时生成批次谱系。

## 数据恢复与核验

在隔离环境恢复 MySQL 和引用清单，校验样例的源对象、派生关系及最终结果。恢复后应能回答以下问题。净缺 100 件，替代造成材料成本增加 20 元。销售交付可追到成品和投入批次，未放行数量不计可交。

[MySQL 事务模型](https://dev.mysql.com/doc/refman/8.4/en/innodb-transaction-model.html)

[产品](01-产品总览-prd.md) · [功能](02-业务与交互-prd.md) · [MySQL](03-MySQL与集成-prd.md) · [验收](04-验收与运营-prd.md) · [开源](05-开源与发布-prd.md) · [状态与操作](06-状态与操作契约-prd.md) · [公开案例](07-开源项目说明与使用案例-prd.md)
