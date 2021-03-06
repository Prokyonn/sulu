<?php

/*
 * This file is part of Sulu.
 *
 * (c) MASSIVE ART WebServices GmbH
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace Sulu\Bundle\PageBundle\Content\Types;

use Sulu\Component\Content\SimpleContentType;

/**
 * ContentType for Phone.
 */
class Phone extends SimpleContentType
{
    public function __construct()
    {
        parent::__construct('Phone', '');
    }
}
