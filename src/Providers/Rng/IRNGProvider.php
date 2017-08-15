<?php

namespace pskuza\Auth\Providers\Rng;

interface IRNGProvider
{
    public function getRandomBytes($bytecount);

    public function isCryptographicallySecure();
}
