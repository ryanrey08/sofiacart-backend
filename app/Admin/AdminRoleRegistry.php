<?php

namespace App\Admin;

final class AdminRoleRegistry
{
    public const SUPER_ADMIN = 'super-admin';

    public const ADMIN = 'admin';

    public const MERCHANT_MANAGER = 'merchant-manager';

    public const ORDER_MANAGER = 'order-manager';

    public const PRODUCT_MANAGER = 'product-manager';

    public const CUSTOMER_SUPPORT = 'customer-support';

    public static function definitions(): array
    {
        return [
            [
                'slug' => self::SUPER_ADMIN,
                'name' => 'Super Admin',
                'description' => 'Full platform access with super admin assignment rights.',
                'permissions' => array_column(AdminPermissionRegistry::definitions(), 'name'),
            ],
            [
                'slug' => self::ADMIN,
                'name' => 'Admin',
                'description' => 'Broad operational access without super-admin delegation.',
                'permissions' => [
                    AdminPermissionRegistry::DASHBOARD_VIEW,
                    AdminPermissionRegistry::MERCHANTS_VIEW,
                    AdminPermissionRegistry::MERCHANTS_BILLING_VIEW,
                    AdminPermissionRegistry::ORDERS_VIEW,
                    AdminPermissionRegistry::PRODUCTS_VIEW,
                    AdminPermissionRegistry::CUSTOMERS_VIEW,
                    AdminPermissionRegistry::PAYMENTS_VIEW,
                    AdminPermissionRegistry::REPORTS_VIEW,
                    AdminPermissionRegistry::SETTINGS_VIEW,
                    AdminPermissionRegistry::USERS_VIEW,
                    AdminPermissionRegistry::ROLES_VIEW,
                    AdminPermissionRegistry::PERMISSIONS_VIEW,
                    AdminPermissionRegistry::LOGS_VIEW,
                ],
            ],
            [
                'slug' => self::MERCHANT_MANAGER,
                'name' => 'Merchant Manager',
                'description' => 'Approves, reviews, and monitors merchants.',
                'permissions' => [
                    AdminPermissionRegistry::DASHBOARD_VIEW,
                    AdminPermissionRegistry::MERCHANTS_VIEW,
                    AdminPermissionRegistry::MERCHANTS_MANAGE,
                    AdminPermissionRegistry::MERCHANTS_BILLING_VIEW,
                    AdminPermissionRegistry::REPORTS_VIEW,
                    AdminPermissionRegistry::LOGS_VIEW,
                ],
            ],
            [
                'slug' => self::ORDER_MANAGER,
                'name' => 'Order Manager',
                'description' => 'Manages orders, payments, and refunds.',
                'permissions' => [
                    AdminPermissionRegistry::DASHBOARD_VIEW,
                    AdminPermissionRegistry::ORDERS_VIEW,
                    AdminPermissionRegistry::ORDERS_MANAGE,
                    AdminPermissionRegistry::ORDERS_REFUND,
                    AdminPermissionRegistry::PAYMENTS_VIEW,
                    AdminPermissionRegistry::PAYMENTS_MANAGE,
                    AdminPermissionRegistry::PAYMENTS_RECONCILE,
                    AdminPermissionRegistry::PAYMENTS_REFUND,
                    AdminPermissionRegistry::REPORTS_VIEW,
                    AdminPermissionRegistry::LOGS_VIEW,
                ],
            ],
            [
                'slug' => self::PRODUCT_MANAGER,
                'name' => 'Product Manager',
                'description' => 'Oversees products and inventory.',
                'permissions' => [
                    AdminPermissionRegistry::DASHBOARD_VIEW,
                    AdminPermissionRegistry::PRODUCTS_VIEW,
                    AdminPermissionRegistry::PRODUCTS_MANAGE,
                    AdminPermissionRegistry::PRODUCTS_APPROVE,
                    AdminPermissionRegistry::PRODUCTS_INVENTORY,
                    AdminPermissionRegistry::REPORTS_VIEW,
                    AdminPermissionRegistry::LOGS_VIEW,
                ],
            ],
            [
                'slug' => self::CUSTOMER_SUPPORT,
                'name' => 'Customer Support',
                'description' => 'Supports customers and reviews orders and payments.',
                'permissions' => [
                    AdminPermissionRegistry::DASHBOARD_VIEW,
                    AdminPermissionRegistry::CUSTOMERS_VIEW,
                    AdminPermissionRegistry::CUSTOMERS_MANAGE,
                    AdminPermissionRegistry::ORDERS_VIEW,
                    AdminPermissionRegistry::PAYMENTS_VIEW,
                    AdminPermissionRegistry::REPORTS_VIEW,
                    AdminPermissionRegistry::LOGS_VIEW,
                ],
            ],
        ];
    }
}
