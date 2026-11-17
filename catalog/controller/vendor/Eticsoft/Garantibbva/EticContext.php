<?php
namespace Eticsoft\Garantibbva;

class EticContext
{
    public static function get($key)
    {   
        return \Context::getContext()->$key;
    }
}