<?php
require __DIR__ . '/lib/bootstrap.php';
do_logout();
redirect('index.php?err=logout');
