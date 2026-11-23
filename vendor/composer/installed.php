<?php return array(
    'root' => array(
        'name' => 'technas/pay-woocommerce',
        'pretty_version' => '0.5.0',
        'version' => '0.5.0.0',
        'reference' => null,
        'type' => 'wordpress-plugin',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => false,
    ),
    'versions' => array(
        'technas/pay' => array(
            'pretty_version' => 'v0.6.0',
            'version' => '0.6.0.0',
            'reference' => '2dcf3d8cf75605efb2e092a7ca414b279d2ee26f',
            'type' => 'library',
            'install_path' => __DIR__ . '/../technas/pay',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'technas/pay-woocommerce' => array(
            'pretty_version' => '0.5.0',
            'version' => '0.5.0.0',
            'reference' => null,
            'type' => 'wordpress-plugin',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
