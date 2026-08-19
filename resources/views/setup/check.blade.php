<?php

$laravelVersion = '8.2';

$reqList = array(
   
    '8.2' => array(
        'php' => '8.2',
        'openssl' => true,
        'fileinfo' => true,
        'pdo' => true,
        'curl' => true,
        'mbstring' => true,
        'tokenizer' => true,
        'xml' => true,
        'ctype' => true,
        'json' => true,
        'bcmath' => true,
        'gd' => true,
        'obs' => ''
    ),
);


$strOk = '<i class="fa fa-check setup-req-icon--ok"></i>';
$strFail = '<i class="fa fa-times setup-req-icon--fail"></i>';
$strUnknown = '<i class="fa fa-question"></i>';

$requirements = array();

$requirements['php_version'] = version_compare(PHP_VERSION, $reqList[$laravelVersion]['php'], ">=");
$requirements['openssl_enabled'] = extension_loaded("openssl");
$requirements['pdo_enabled'] = defined('PDO::ATTR_DRIVER_NAME');
$requirements['mbstring_enabled'] = extension_loaded("mbstring");
$requirements['curl_enabled'] = extension_loaded("curl");
$requirements['tokenizer_enabled'] = extension_loaded("tokenizer");
$requirements['xml_enabled'] = extension_loaded("xml");
$requirements['ctype_enabled'] = extension_loaded("ctype");
$requirements['fileinfo_enabled'] = extension_loaded("fileinfo");
$requirements['gd_enabled'] = extension_loaded("gd");
$requirements['json_enabled'] = extension_loaded("json");
$requirements['bcmath_enabled'] = extension_loaded("bcmath");

$allValuesAreTrue = (count(array_unique($requirements)) === 1);

?>

@extends('setup.main')
@section('content')

<div class="row">
    <div class="col-12 text-center mt-3">
        <ul class="progressbar"> 
            <li class="active"><a href="/setup">Requisitos del servidor</a></li>
            <li>Configuración</li>
            <li>Base de datos</li>
            <li>Resumen</li>
        </ul>
    </div>
</div>

<div class="row mt-3 p-5">
    <div class="col-12">
    @if (session('error'))
        <p class="alert alert-danger">{{ session('error') }}</p>
    @endif
    @if (! $allValuesAreTrue)
     <p class="alert alert-danger">El servidor no cumple con los siguientes requisitos</p> 
    @endif
        <ul class="list-group">
            <li class="list-group-item d-flex justify-content-between align-items-center">
                PHP <?php
                if (is_array($reqList[$laravelVersion]['php'])) {
                    $phpVersions = array();
                    foreach ($reqList[$laravelVersion]['php'] as $operator => $version) {
                        $phpVersions[] = "{$operator} {$version}";
                    }
                    echo implode(" && ", $phpVersions);
                } else {
                    echo ">= " . $reqList[$laravelVersion]['php'];
                }?>
                    <span><?php echo " " . ($requirements['php_version'] ? $strOk : $strFail); ?>
                (<?php echo PHP_VERSION; ?>)</span>
            </li>  

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['openssl']) : ?>
                    <p>Extensión PHP OpenSSL</p>
                <?php endif; ?>
                <span><?php echo $requirements['openssl_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['gd']) : ?>
                    <p>Extensión PHP GD</p>
                <?php endif; ?>
                <span><?php echo $requirements['gd_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['fileinfo']) : ?>
                    <p>Extensión PHP fileinfo</p>
                <?php endif; ?>
                <span><?php echo $requirements['fileinfo_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['pdo']) : ?>
                    <p>Extensión PHP PDO</p>
                <?php endif; ?>
                <span><?php echo $requirements['pdo_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['mbstring']) : ?>
                <p>Extensión PHP Mbstring</p>
                <?php endif ?>
                <span><?php echo $requirements['mbstring_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['curl']) : ?>
                <p>Extensión PHP Curl</p>
                <?php endif ?>
                <span><?php echo $requirements['curl_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['tokenizer']) : ?>
                <p>Extensión PHP Tokenizer</p>
                <?php endif ?>
                <span><?php echo $requirements['tokenizer_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['xml']) : ?>
                <p>Extensión PHP XML</p>
                <?php endif ?>
                <span><?php echo $requirements['xml_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['ctype']) : ?>
                <p>Extensión PHP CTYPE</p>
                <?php endif ?>
                <span><?php echo $requirements['ctype_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if ($reqList[$laravelVersion]['json']) : ?>
                <p>Extensión PHP JSON</p>
                <?php endif ?>
                <span><?php echo $requirements['json_enabled'] ? $strOk : $strFail; ?></span>
            </li>

            <li class="list-group-item d-flex justify-content-between align-items-center">
                <?php if (isset($reqList[$laravelVersion]['bcmath']) && $reqList[$laravelVersion]['bcmath']) : ?>
                <p>Extensión PHP BCMath</p>
                <?php endif ?>
                <span><?php echo $requirements['bcmath_enabled'] ? $strOk : $strFail; ?></span>
            </li>

        </ul>
    </div>

    @if ($allValuesAreTrue)
        <div class="offset-6 col-6 col-md-6">
            <a href="/setup/step-1" id="next" class="btn btn-outline-danger mt-3 float-md-right">Siguiente paso <i class="fa fa-angle-right"></i></a>
        </div>
    @endif
</div>

@endsection