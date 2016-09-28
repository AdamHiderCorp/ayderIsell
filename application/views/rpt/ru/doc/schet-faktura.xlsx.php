<?php
$okei = [
    'шт' => '796',
    'руб'=>'383',
    '1000 руб'=>'384',
    'компл'=>'839',
    'л'=>'112',
    'усл. ед'=>'876',
    'кг'=>'166',
    'т'=>'168',
    'ч'=>'356',
    'м' => '006',
    'м2'=>'055',
    'пог. м'=>'018',
    'упак'=>'778'
];
foreach($this->view->rows as &$row){
    $row->vat_rate=$this->view->head->vat_rate;
    $row->product_unit_code = $okei[$row->product_unit];
    $row->product_sum_total = format($row->product_quantity*round($row->product_price*(1+$row->vat_rate),2));
    $row->product_sum = format($row->product_sum_total/(1+$row->vat_rate));
    $row->product_sum_vat = format($row->product_sum_total-$row->product_sum);
    
    
    
    $row->product_price=format($row->product_price*$vat_ratio);
    $row->product_sum=format($row->product_price*$row->product_quantity);
}