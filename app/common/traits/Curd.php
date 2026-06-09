<?php
use think\model\concern\SoftDelete;
trait  Curd
{
    use SoftDelete;
    public function  index()
    {
        if (request()->isAjax()){
            if (request()->param('selectFields')){

            }
        }
    }

    protected  function  selectList()
    {
        if (input('selectFields')&& input('showField')){

        }
    }

    protected function   selectPage()
    {
        request()->filter(['trim','strip_tags','htmlspecialchars']);
        //搜索关键词，客户端输入以空格分开，接手为数组
        $word = (array)request()->param('q_word/a');
        $word = array_filter(array_unique($word));
        $searchTable = request()->param('searchTable/s');
        $class= "\\app\\common\\model\\".\think\helper\Str::studly($searchTable);
        if ($searchTable && class_exists($class)) $this->modelClass = new $class;
        //当前页
        $page = request()->param("pageNumber",1);
        //分页大小
        $pagesize = request()->param("pageSize",10);
        //搜索条件
        $andor = request()->param("andOr", 'AND', "strtoupper");
        //排序方式
        $orderby = (array) request()->param("orderBy/a");
        //显示的字段
        $field = request()->request("showField");
        //主键
        $primarykey = request()->param("keyField");
        //主键值
        $primaryvalue = request()->param("keyValue");
        //搜索字段
        $searchfield = (array) request()->param("selectFields/a")  ;
        //是否返回树形结构
        $istree = request()->param("isTree/d", 0);
        $ishtml = request()->param("isHtml/d", 0);

        if ($istree){
            $word = [];
            $pagesize = 9999;
        }
        $order= [];
        foreach ($orderby as $k => $v){
            if ($v == 'false');continue;
            $order[$v[0]]= $v[1];
        }
        $where = [];
        $whereOr = [];
        //如果primaryValue 说明当前是初始化传值

        if ($primaryvalue !== null){
            $where[] = [$primaryvalue,'in',explode(',',$primaryvalue)];
            $pagesize = 9999;
        }else{
            $logic= $andor == 'AND' ? '&' : '|';
            
        }
    }

}