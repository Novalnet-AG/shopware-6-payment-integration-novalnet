Shopware.Service('privileges')
    .addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'orders',
        key: 'novalnet_extension',
        roles: {
            viewer: {
                privileges: [
                    'novalnet_transaction_details:read',
                ],
                dependencies: [],
            },
            editor: {
                privileges: [
                    'novalnet_transaction_details:update',
                    'order_transaction:read',
                    'order_transaction:update',
                ],
                dependencies: [
                    'novalnet_extension.viewer',
                    'order.editor',
                ],
            },
            creator: {
                privileges: [
                    'novalnet_transaction_details:create',
                ],
                dependencies: [
                    'novalnet_extension.viewer',
                    'novalnet_extension.editor',
                ],
            },
            deleter: {
                privileges: [
                    'novalnet_transaction_details:delete',
                ],
                dependencies: [
                    'novalnet_extension.viewer',
                ],
            },
        },
    });
Shopware.Service('privileges').addPrivilegeMappingEntry({
    category: 'permissions',
    parent: 'novalnet_payment',
    key: 'novalnet_payment',
    roles: {
        viewer: {
            privileges: [
                'system_config:read',
                'sales_channel:read',
            ],
            dependencies: [],
        },
        editor: {
            privileges: [
                'system_config:update',
                'system_config:create',
                'system_config:delete',
                'sales_channel:update',
            ],
            dependencies: [
                'novalnet_payment.viewer',
            ],
        },
    },
});
