<?php
/**
 * User: summerpassion
 * DateTime: 2019/8/23 14:51
 * version 1.0.0
 */

namespace mustang\nestedsets;


use think\Db;
use think\Exception;

class Mptt
{
    /**
     * @var 表名
     */
    private $tableName;

    /**
     * @var string 左键
     */
    private $leftKey = "lft";

    /**
     * @var string 右键
     */
    private $rightKey = "rht";

    /**
     * @var string 父亲字段
     */
    private $parentKey = "pid";

    /**
     * @var string 节点深度
     */
    private $levelKey = "lev";

    /**
     * @var string 主键
     */
    private $primaryKey = "id";

    /**
     * @var array 节点的缓存
     */
    private static $itemCache = [];

    /**
     * @var array 重建数据
     */
    private $metaData = null;

    /**
     * @var bool 排序选项
     * 1.0.0暂未处理
     */
    private $opt_ord = [
        "switch" => false,
        "flag" => "ordby"
    ];

    /**
     * NestedSets constructor.
     * @param $dbTarg mixed 数据表名或者模型对象
     * @param null $leftKey
     * @param null $rightKey
     * @param null $parentKey
     * @param null $levelKey
     * @param null $primaryKey
     * @throws Exception
     */
    public function __construct($dbTarg, $leftKey = null, $rightKey = null, $parentKey = null, $levelKey = null, $primaryKey = null)
    {
        //如果是表名则处理配置
        if (is_string($dbTarg)) {
            $this->tableName = $dbTarg;
        }

        //允许传入模型对象
        if (is_object($dbTarg)) {
            if (method_exists($dbTarg, 'getTable')) {
                throw new Exception('不能传入该对象');
            }

            $this->tableName = $dbTarg->getTable();
            if (property_exists($dbTarg, 'nestedConfig') && is_array($dbTarg->nestedConfig)) {
                isset($dbTarg->nestedConfig['leftKey']) && $this->leftKey = $dbTarg->nestedConfig['leftKey'];
                isset($dbTarg->nestedConfig['rightKey']) && $this->rightKey = $dbTarg->nestedConfig['rightKey'];
                isset($dbTarg->nestedConfig['parentKey']) && $this->parentKey = $dbTarg->nestedConfig['parentKey'];
                isset($dbTarg->nestedConfig['primaryKey']) && $this->primaryKey = $dbTarg->nestedConfig['primaryKey'];
                isset($dbTarg->nestedConfig['levelKey']) && $this->levelKey = $dbTarg->nestedConfig['levelKey'];
            }
        }

        //构造方法中传入的配置会覆盖其他方式的配置
        isset($leftKey) && $this->leftKey = $leftKey;
        isset($rightKey) && $this->rightKey = $rightKey;
        isset($parentKey) && $this->parentKey = $parentKey;
        isset($primaryKey) && $this->primaryKey = $primaryKey;
        isset($levelKey) && $this->levelKey = $levelKey;
    }

    /************************** 向下查 **************************/

    /**
     * @return false|\PDOStatement|string|\think\Collection
     * 获取整棵树
     */
    public function getTree()
    {
        return Db::table($this->tableName)->order("{$this->leftKey}")->select();
    }

    /**
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     * 获取该节点的所有直推节点
     */
    public function getChild($id)
    {
        return Db::table($this->tableName)
            ->where($this->parentKey, '=', $id)
            ->order("{$this->leftKey}")
            ->select();
    }

    /**
     * @param $id
     * @param string $optionOne
     * @param string $optionTwo
     * @param int $lev_step 相对层级深度,非0则查询当前节点下$lev_step层
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     * 获取当前节点的所有分支节点|不包含当前节点
     */
    public function getBranch($id, $lev_step = 0, $optionOne = '>', $optionTwo = '<')
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }
        $current_lev = $item[$this->levelKey];
        $level = $lev_step > 0 ? ['lev' => ['<=', $current_lev + $lev_step]] : [];
        return Db::table($this->tableName)
            ->where($level)
            ->where($this->leftKey, $optionOne, $item[$this->leftKey])
            ->where($this->rightKey, $optionTwo, $item[$this->rightKey])
            ->order("{$this->leftKey}")
            ->select();
    }

    /**
     * @param $id
     * @param $lev_step 相对层级
     * @return false|\PDOStatement|string|\think\Collection
     * 获取当前节点的所有分支节点 | 包含当前节点
     */
    public function getPath($id, $lev_step = 0)
    {
        return $this->getBranch($id, $lev_step, ">=", "<=");
    }

    /**
     * 获取指定id的后代总数
     * @param $id
     * @return int|mixed
     * @throws Exception
     */
    public function getBranchCount($id)
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }
        return ($item[$this->rightKey] - $item[$this->leftKey] - 1) / 2;
    }

    /************************** 向上查 **************************/

    /**
     * @param $id
     * @return false|\PDOStatement|string|\think\Collection
     * 获取该节点的父级节点
     */
    public function getParent($id)
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }
        return Db::table($this->tableName)
            ->where($this->primaryKey, '=', $item[$this->parentKey])
            ->order("{$this->leftKey}")
            ->select();
    }

    /**
     * 查询指定ID的上层节点
     * @param $id
     * @param $lev_step 相对层级深度,非0则查询当前节点下$lev_step层
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getParents($id, $lev_step = 0)
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }
        $current_lev = $item[$this->levelKey];
        $level = $lev_step > 0 ? ['lev'=>['between', [$current_lev - $lev_step, $current_lev]]] : [];
        return Db::table($this->tableName)
            ->where($level)
            ->where($this->leftKey, '<', $item[$this->leftKey])
            ->where($this->rightKey, '>', $item[$this->rightKey])
            ->order("{$this->leftKey}")
            ->select();
    }

    /************************** 中间查 **************************/

    /**
     * 判断两个节点是否属于同一条线
     * @param $top_id
     * @param $bottom_id
     * @return bool
     * @throws Exception
     */
    public function inOneLine($top_id, $bottom_id)
    {
        $top = $this->getItem($top_id);
        $bottom = $this->getItem($bottom_id);
        if ($top && $bottom) {
            $range = Db::table($this->tableName)
                ->where($this->leftKey, 'between', [$top[$this->leftKey], $bottom[$this->leftKey]])
                ->where($this->rightKey, 'between', [$bottom[$this->rightKey], $top[$this->rightKey]])
                ->column('id');
            $ids = array_values($range);
            return in_array($top_id, $ids) && in_array($bottom_id, $ids);
        } else {
            throw new Exception('节点号错误');
        }
    }

    /**
     * 给定顶点和底点,查询中间的所有节点
     * @param $top_id 上层顶点的id
     * @param $bottom_id 底层端点的id
     * @return false|\PDOStatement|string|\think\Collection
     * @throws Exception
     * @throws \think\db\exception\DataNotFoundException
     * @throws \think\db\exception\ModelNotFoundException
     * @throws \think\exception\DbException
     */
    public function getRange($top_id, $bottom_id)
    {
        $top = $this->getItem($top_id);
        $bottom = $this->getItem($bottom_id);
        if ($top && $bottom) {
            return Db::table($this->tableName)
                ->where($this->leftKey, 'between', [$top[$this->leftKey], $bottom[$this->leftKey]])
                ->where($this->rightKey, 'between', [$bottom[$this->rightKey], $top[$this->rightKey]])
                ->order("{$this->leftKey}")
                ->select();
        } else {
            throw new Exception('节点号错误');
        }
    }

    /************************** 节点操作 **************************/

    /**
     * @param $parentId
     * @param array $data
     * @param string $position top|bottom
     * @return int|string
     * 添加新节点,$data中必须有键名为mid
     */
    public function insert($parentId, array $data = [], $position = "top")
    {
        $parent = $this->getItem($parentId);

        if (!$parent) {
            $parentId = 0;
            $level = 1;
            if ($position == "top") {
                $key = 1;
            } else {
                $key = Db::table($this->tableName)
                        ->max("{$this->rightKey}") + 1;
            }
        } else {
            $key = ($position == "top") ? $parent[$this->leftKey] + 1 : $parent[$this->rightKey];
            $level = $parent[$this->levelKey] + 1;
        }

        //更新其他节点
        $sql = "UPDATE {$this->tableName} SET {$this->rightKey} = {$this->rightKey}+2,{$this->leftKey} = IF({$this->leftKey}>={$key},{$this->leftKey}+2,{$this->leftKey}) WHERE {$this->rightKey}>={$key}";
        Db::table($this->tableName)
            ->query($sql);

        $newNode[$this->parentKey] = $parentId;
        $newNode[$this->leftKey] = $key;
        $newNode[$this->rightKey] = $key + 1;
        $newNode[$this->levelKey] = $level;
        $tmpData = array_merge($newNode, $data);

        Db::table($this->tableName)->insert($tmpData);
    }

    /**
     * @param $id
     * @return bool
     * @throws Exception
     * 删除某个节点以及所有伞下子节点
     */
    public function delete($id)
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }

        $keyWidth = $item[$this->rightKey] - $item[$this->leftKey] + 1;

        try {
            $del = Db::table($this->tableName)
                ->where($this->leftKey, '>=', $item[$this->leftKey])
                ->where($this->rightKey, '<=', $item[$this->rightKey])
                ->delete();
            $sql = "UPDATE {$this->tableName} SET {$this->leftKey} = IF({$this->leftKey}>{$item[$this->leftKey]}, {$this->leftKey}-{$keyWidth}, {$this->leftKey}), {$this->rightKey} = {$this->rightKey}-{$keyWidth} WHERE {$this->rightKey}>{$item[$this->rightKey]}";
            //再移动节点
            Db::table($this->tableName)->query($sql);

            return true;
        } catch (Exception $e) {
            return false;
        }
    }

    /**
     * @param $id
     * @param $parentId
     * @param string $position bottom表示在后边插入   top表示开始插入
     * @return bool
     * @throws Exception
     * 将一个节点及其全部后代移动到另个一节点下,两者为父子级关系
     */
    public function moveUnder($id, $parentId, $position = "bottom")
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }

        $parent = $this->getItem($parentId);

        if (!$parent) {
            $level = 1;
            // 在顶部插入
            if ($position == 'top') {
                $nearKey = 0;
            } else {
                // 选择最大的右键作为开始
                $nearKey = Db::table($this->tableName)
                    ->max("{$this->rightKey}");
            }
        } else {
            $level = $parent[$this->levelKey] + 1;
            if ($position == 'top') {
                $nearKey = $parent[$this->leftKey];
            } else {
                //若在底部插入则起始键为父节点的右键减1
                $nearKey = $parent[$this->rightKey] - 1;
            }
        }

        return $this->move($id, $parentId, $nearKey, $level);
    }

    /**
     * @param $id
     * @param $nearId
     * @param string $position
     * @return bool
     * @throws Exception
     * 把主键为id的整条线移动到主键为nearId的节点的前或者后,两者隶属同一个父节点，两者互为兄弟节点
     */
    public function moveNear($id, $nearId, $position = 'after')
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception("要移动的节点不存在");
        }

        $near = $this->getItem($nearId);
        if (!$near) {
            throw new Exception("附近的节点不存在");
        }

        $level = $near[$this->levelKey];

        //根据要移动的位置选择键
        if ($position == 'before') {
            $nearKey = $near[$this->leftKey] - 1;
        } else {
            $nearKey = $near[$this->rightKey];
        }

        //移动节点
        return $this->move($id, $near[$this->parentKey], $nearKey, $level);

    }

    /**
     * @param $id
     * @param $parentId
     * @param $nearKey
     * @param $level
     * @return bool
     * 移动节点
     */
    private function move($id, $parentId, $nearKey, $level)
    {
        $item = $this->getItem($id);

        //检查能否移动该节点若为移动到节点本身下则返回错误
        if ($nearKey >= $item[$this->leftKey] && $nearKey <= $item[$this->rightKey]) {
            return false;
        }

        $keyWidth = $item[$this->rightKey] - $item[$this->leftKey] + 1;
        $levelWidth = $level - $item[$this->levelKey];

        if ($item[$this->rightKey] < $nearKey) {
            $treeEdit = $nearKey - $item[$this->leftKey] + 1 - $keyWidth;
            $sql = "UPDATE {$this->tableName} 
                    SET 
                    {$this->leftKey} = IF(
                        {$this->rightKey} <= {$item[$this->rightKey]},
                        {$this->leftKey} + {$treeEdit},
                        IF(
                            {$this->leftKey} > {$item[$this->rightKey]},
                            {$this->leftKey} - {$keyWidth},
                            {$this->leftKey}
                        )
                    ),
                    {$this->levelKey} = IF(
                        {$this->rightKey} <= {$item[$this->rightKey]},
                        {$this->levelKey} + {$levelWidth},
                        {$this->levelKey}
                    ),
                    {$this->rightKey} = IF(
                        {$this->rightKey} <= {$item[$this->rightKey]},
                        {$this->rightKey} + {$treeEdit},
                        IF(
                            {$this->rightKey} <= {$nearKey},
                            {$this->rightKey} - {$keyWidth},
                            {$this->rightKey}
                        )
                    ),
                    {$this->parentKey} = IF(
                        {$this->primaryKey} = {$id},
                        {$parentId},
                        {$this->parentKey}
                    )
                    WHERE 
                    {$this->rightKey} > {$item[$this->leftKey]}
                    AND 
                    {$this->leftKey} <= {$nearKey}";
            Db::table($this->tableName)->query($sql);
        } else {
            $treeEdit = $nearKey - $item[$this->leftKey] + 1;

            $sql = "UPDATE {$this->tableName}
                    SET 
                    {$this->rightKey} = IF(
						{$this->leftKey} >= {$item[$this->leftKey]},
						{$this->rightKey} + {$treeEdit},
						IF(
							{$this->rightKey} < {$item[$this->leftKey]},
							{$this->rightKey} + {$keyWidth},
							{$this->rightKey}
						)
					),
					{$this->levelKey} = IF(
						{$this->leftKey} >= {$item[$this->leftKey]},
						{$this->levelKey} + {$levelWidth},
						{$this->levelKey}
					),
					{$this->leftKey} = IF(
						{$this->leftKey} >= {$item[$this->leftKey]},
						{$this->leftKey} + {$treeEdit},
						IF(
							{$this->leftKey} > {$nearKey},
							{$this->leftKey} + {$keyWidth},
							{$this->leftKey}
						)
					),
					{$this->parentKey} = IF(
						{$this->primaryKey} = {$id},
						{$parentId},
						{$this->parentKey}
					)
					WHERE
					{$this->rightKey} > {$nearKey}
					AND
					{$this->leftKey} < {$item[$this->rightKey]}";
            Db::table($this->tableName)->query($sql);
        }

        return true;

    }

    /**
     * @param $id
     * @return mixed
     * 根据ID获取某个节点
     */
    private function getItem($id)
    {
        if (!isset(self::$itemCache[$id])) {
            self::$itemCache[$id] =
                Db::table($this->tableName)
                    ->field([$this->leftKey, $this->rightKey, $this->parentKey, $this->levelKey])
                    ->where($this->primaryKey, '=', $id)
                    ->find();
        }

        return self::$itemCache[$id];
    }

    /**
     * 转mptt结构
     * 如果数据量和深度较大，慎用！
     * 递归重建
     * Rebuilds all trees in the database table using `pid` link.
     */
    public function rebuildMptt()
    {
        $mptt_meta = $this->metaData = Db::table($this->tableName)->select();

        $root = array_filter($this->metaData, function ($item) {
            return 0 == $item['pid'];
        });

        if (!$root) {
            return;
        }

        if (1 < count($root)) {
            throw new Exception("结构异常：根节点不止一个！");
        }

        return $this->rebuild_helper($root[0]['mid'], 1);
    }

    /**
     * 重建函数
     * @param $pk
     * @param $lft
     * @param int $lev
     */
    private function rebuild_helper($pk, $lft, $lev = 0)
    {
        $rht = $lft + 1;

        $child_ids = array_filter($this->metaData, function ($item) use ($pk) {
            return $item['pid'] == $pk;
        });

        if ($this->opt_ord["switch"]) {
            array_multisort(array_column($child_ids, $this->opt_ord["flag"]), SORT_ASC, $child_ids);
        }

        while (false != $piece = array_shift($child_ids)) {
            $rht = $this->rebuild_helper($piece['mid'], $rht, $lev + 1);
        }

        Db::table($this->tableName)->where(["mid" => $pk])->update([
            "lft" => $lft,
            "rht" => $rht,
            "lev" => $lev
        ]);

        return $rht + 1;
    }

    /**
     * 查询指定ID的上层ID集合
     * @param $id 底层ID
     * @param int $lev_step 相对层级
     * @param bool $withSelf 默认不包含自己
     * @return array
     * @throws Exception
     */
    public function getParentIds($id, $lev_step = 0, $withSelf = false)
    {
        $item = $this->getItem($id);
        if (!$item) {
            throw new Exception('没有该节点');
        }
        if($withSelf){
            $lt = '<=';
            $gt = '>=';
        }else{
            $lt = '<';
            $gt = '>';
        }
        $current_lev = $item[$this->levelKey];
        $level = $lev_step > 0 ? ['lev'=>['between', [$current_lev - $lev_step, $current_lev]]] : [];
        return Db::table($this->tableName)
            ->where($level)
            ->where($this->leftKey, $lt, $item[$this->leftKey])
            ->where($this->rightKey, $gt, $item[$this->rightKey])
            ->order("{$this->leftKey}")
            ->column('mid');
    }
}
