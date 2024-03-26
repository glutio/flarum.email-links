<?php

namespace Glutio\EmailLinks;

use Flarum\Extend;

return [
  (new Extend\ServiceProvider())
    ->register(MailServiceProviderWrapper::class)
];
