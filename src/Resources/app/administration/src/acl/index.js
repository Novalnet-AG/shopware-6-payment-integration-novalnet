Shopware.Service('privileges')
    .addPrivilegeMappingEntry({
        category: 'permissions',
        parent: 'orders',
        key: 'novalnet_extension',
        roles: {
            viewer: {
                privileges: [
                    'novalnet_transaction_details:read',
                    'novalnet_payment_token:read',
                ],
                dependencies: [],
            },
            editor: {
                privileges: [
                    'novalnet_transaction_details:update',
                    'novalnet_payment_token:update',
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
                    'novalnet_payment_token:create',
                ],
                dependencies: [
                    'novalnet_extension.viewer',
                    'novalnet_extension.editor',
                ],
            },
            deleter: {
                privileges: [
                    'novalnet_transaction_details:delete',
                    'novalnet_payment_token:delete',
                ],
                dependencies: [
                    'novalnet_extension.viewer',
                ],
            },
        },
    });
