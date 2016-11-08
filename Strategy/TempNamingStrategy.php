<?php


namespace SRIO\RestUploadBundle\Strategy;


use SRIO\RestUploadBundle\Upload\UploadContext;

class TempNamingStrategy implements NamingStrategy
{

    public function getName(UploadContext $context)
    {
        return uniqid().'.tmp';
    }
}