<?php
include 'models/Catalog.php';
class MiSell extends Catalog{
    public $fns=array(
        'html'=>'',
        'suggest'=>'(string) q,(int) parent_id,[\w_0-9]* callback',
        'orderCalculate'=>'(json) order,(int) company_id',
        'orderSend'=>'(json) order,(int) company_id'
    );
    public function html(){
        $this->Base->set_level(1);
        header("Content-Type:text/html;charset=utf-8");
        include_once 'libraries/report/RainTpl.php';
        raintpl::configure( 'tpl_dir', 'application/plugins/MiSell/' );
        raintpl::configure( 'cache_dir', 'plugins/MiSell/cache/' );
        $tplData=$this->getTplData();
        $this->rain=new RainTPL();
        $this->rain->assign('d',$tplData);
        $this->rain->assign('db', json_encode($tplData) );
        $this->rain->draw('MiSell');
        exit();
    }
    private function getTplData(){
        $d=array();
        $d['stock_tree']=$this->getSubBranches('stock_tree', array('branch_id'=>0), 2);
        $d['companies_tree']=$this->getCompaniesTree();
        $d['user_sign']=$this->Base->svar('user_sign');
        return $d;
    }
    public function getCompaniesTree_(){
	$tree=[];
	$level=$this->Base->svar('user_level');
	$assigned_path=  $this->Base->svar('user_assigned_path');
	$companies_folder_list=$this->get_list("SELECT branch_id,label FROM companies_tree WHERE is_leaf=0 AND level<=$level AND path LIKE '$assigned_path%'");
	foreach($companies_folder_list as $folder){
	    $sql="SELECT
			company_id,
			label,
			company_address,
			company_person,
			company_mobile,
			company_description
		    FROM
			companies_tree 
			    JOIN 
			companies_list USING(branch_id) 
		    WHERE 
			is_leaf=1 
			AND level<=$level 
			AND parent_id='{$folder->branch_id}'";
	    $companies_list=$this->get_list($sql);
	    if($companies_list){
		$folder->children=$companies_list;
		$tree[]=$folder;
	    }
	}
	return $tree;
    }
    public function getCompaniesTree(){
        $list=$this->getManagerClients($this->Base->svar('user_id'));
        $this->Base->svar('allowed_comps',$list);
        $tree=array();
        $folder="";
        foreach($list as $item){
            if($item->folder!=$folder){
                $folder=$item->folder;
                $tree["$folder"]=array();
            }
            $tree["$folder"][]=$item;
        }
        return $tree;
    }
    private function getManagerClients( $user_id ){
        $sql="SELECT 
                COALESCE(ct2.label,'...') folder,
                ct.label label,
                company_id,
                company_person,
                company_mobile,
                company_address,
                company_description
            FROM
                companies_list cl
                    JOIN
                companies_tree ct ON cl.branch_id = ct.branch_id
                    LEFT JOIN
                companies_tree ct2 ON ct.parent_id = ct2.branch_id
            WHERE
                manager_id = $user_id
            ORDER BY ct2.label";
        return $this->get_list($sql);
    }
    private function getSubBranches( $table_name, $branch, $depth ){
        return $this->treeFetch('stock_tree',0);
    }
    public function suggest(){
	$q=$this->request('q');
	$parent_id=$this->request('parent_id');
	$company_id=$this->request('company_id','int',0);
        $clues=explode(' ',$q);
	$usd_ratio=$this->Base->pref('usd_ratio');
	
	
	$cases=$parent_id?["parent_id='$parent_id'"]:[];
        foreach($clues as $clue){
            $cases[]="(product_code LIKE '%$clue%' OR ru LIKE '%$clue%')";
        }
        $where=implode(' AND ',$cases);
        $sql="SELECT 
                    product_code code,
                    ru name,
                    product_spack spack,
                    product_quantity<>0 AS instock,
                    product_unit unit,
		    
		    ROUND(
		     (SELECT IF(curr_code='USD',$usd_ratio,1)*sell FROM price_list pl WHERE pl.product_code=se.product_code AND label='')
		    *COALESCE((SELECT discount FROM companies_discounts cd JOIN stock_tree st ON st.top_id=cd.branch_id WHERE st.branch_id=se.parent_id AND company_id=$company_id),1)
			,2)
		    price
		    
                FROM
                    prod_list
                JOIN
                    stock_entries se USING (product_code)
                WHERE $where
                ORDER BY fetch_count - DATEDIFF(NOW(), fetch_stamp) DESC, product_code
                LIMIT 20";
        return $this->get_list($sql);
    }
    public function orderCalculate($order,$company_id){
        if( !$this->checkCompanyId($company_id) ){
            return "Can't use this client!!!";
        }
        $this->Base->LoadClass('Pref');
        $prefs=$this->Base->Pref->getPrefs('dollar_ratio');
        foreach($order as $product_code=>$entry){
            $order[$product_code]['price']=$this->orderGetPrice($product_code, $company_id,$prefs['dollar_ratio']);
        }
        return $order;
    }
    public function orderSend($order,$company_id){
         if( !$this->checkCompanyId($company_id) ){
            return "Can't use this client!!!";
        }
        $this->Base->selectPassiveCompany($company_id);
        $this->Base->LoadClass('Document');
        $this->Base->Document->add(1);
		$this->Base->Document->updateHead( 'Интернет заказ', "doc_data" );
        foreach($order as $product_code=>$entry){
            $this->Base->Document->addEntry( $product_code, $entry['qty'] );
        }
        $this->orderAnnounceRecieved();
        return true;
    }
    private function orderAnnounceRecieved(){
        $this->Base->LoadClass('Utils');
        $pcomp_name=$this->Base->pcomp('company_name');
        $user_sign=$this->Base->svar('user_sign');
        $text="Пользователем $user_sign, был прислан заказ для $pcomp_name";
        $this->Base->Utils->sendEmail( "sell@nilson.ua", "Мобильный заказ $user_sign", $text, NULL, 'nocopy' );
        $this->Base->Utils->sendSms("380955983001","",$text);
		$this->Base->Utils->sendSms("380500377536","",$text);
    }
    private function orderGetPrice($product_code,$company_id,$dollar_ratio){
        $sql="SELECT 
                discount
            FROM
                companies_discounts cd
                    JOIN
                stock_tree st ON (cd.branch_id = st.top_id)
                    JOIN
                stock_entries se ON (st.branch_id = se.parent_id)
            WHERE
                se.product_code = '$product_code'
                    AND cd.company_id = '$company_id'";
        $discount=$this->Base->get_row($sql,0);
        $discount!==NULL?$discount:1;
        $row=$this->Base->get_row("SELECT * FROM price_list WHERE '$product_code'=product_code OR '$product_code' RLIKE CONCAT('^',product_code,'$')");
        $sell=$discount*($row['price_uah']?$row['price_uah']:$row['price_usd']*$dollar_ratio);
        return round($sell,2);
    }
    private function checkCompanyId($company_id){
        $list=$this->Base->svar('allowed_comps');
        foreach($list as $comp){
            if($comp['company_id']==$company_id)
                return true;
        }
        return false;
    }
}
?>