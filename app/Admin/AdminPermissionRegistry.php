<?php

namespace App\Admin;

final class AdminPermissionRegistry
{
    public const DASHBOARD_VIEW = 'dashboard.view';

    public const MERCHANTS_VIEW = 'merchants.view';

    public const MERCHANTS_MANAGE = 'merchants.manage';

    public const MERCHANTS_BILLING_VIEW = 'merchants.billing.view';

    public const ORDERS_VIEW = 'orders.view';

    public const ORDERS_MANAGE = 'orders.manage';

    public const ORDERS_REFUND = 'orders.refund';

    public const PRODUCTS_VIEW = 'products.view';

    public const PRODUCTS_MANAGE = 'products.manage';

    public const PRODUCTS_APPROVE = 'products.approve';

    public const PRODUCTS_INVENTORY = 'products.inventory.manage';

    public const CUSTOMERS_VIEW = 'customers.view';

    public const CUSTOMERS_MANAGE = 'customers.manage';

    public const PAYMENTS_VIEW = 'payments.view';

    public const PAYMENTS_MANAGE = 'payments.manage';

    public const PAYMENTS_RECONCILE = 'payments.reconcile';

    public const PAYMENTS_REFUND = 'payments.refund';

    public const REPORTS_VIEW = 'reports.view';

    public const REPORTS_EXPORT = 'reports.export';

    public const SETTINGS_VIEW = 'settings.view';

    public const SETTINGS_MANAGE = 'settings.manage';

    public const USERS_VIEW = 'users.view';

    public const USERS_MANAGE = 'users.manage';

    public const USERS_ASSIGN_ROLES = 'users.assign_roles';

    public const USERS_ASSIGN_SUPER_ADMIN = 'users.assign_super_admin';

    public const ROLES_VIEW = 'roles.view';

    public const ROLES_MANAGE = 'roles.manage';

    public const PERMISSIONS_VIEW = 'permissions.view';

    public const PERMISSIONS_MANAGE = 'permissions.manage';

    public const LOGS_VIEW = 'logs.view';

    public static function definitions(): array
    {
        return [
            ['name' => self::DASHBOARD_VIEW, 'group' => 'dashboard', 'label' => 'View dashboard'],
            ['name' => self::MERCHANTS_VIEW, 'group' => 'merchants', 'label' => 'View merchants'],
            ['name' => self::MERCHANTS_MANAGE, 'group' => 'merchants', 'label' => 'Manage merchants'],
            ['name' => self::MERCHANTS_BILLING_VIEW, 'group' => 'merchants', 'label' => 'View merchant billing'],
            ['name' => self::ORDERS_VIEW, 'group' => 'orders', 'label' => 'View orders'],
            ['name' => self::ORDERS_MANAGE, 'group' => 'orders', 'label' => 'Manage orders'],
            ['name' => self::ORDERS_REFUND, 'group' => 'orders', 'label' => 'Create refunds'],
            ['name' => self::PRODUCTS_VIEW, 'group' => 'products', 'label' => 'View products'],
            ['name' => self::PRODUCTS_MANAGE, 'group' => 'products', 'label' => 'Manage products'],
            ['name' => self::PRODUCTS_APPROVE, 'group' => 'products', 'label' => 'Approve products'],
            ['name' => self::PRODUCTS_INVENTORY, 'group' => 'products', 'label' => 'Manage inventory'],
            ['name' => self::CUSTOMERS_VIEW, 'group' => 'customers', 'label' => 'View customers'],
            ['name' => self::CUSTOMERS_MANAGE, 'group' => 'customers', 'label' => 'Manage customers'],
            ['name' => self::PAYMENTS_VIEW, 'group' => 'payments', 'label' => 'View payments'],
            ['name' => self::PAYMENTS_MANAGE, 'group' => 'payments', 'label' => 'Manage payments'],
            ['name' => self::PAYMENTS_RECONCILE, 'group' => 'payments', 'label' => 'Reconcile payments'],
            ['name' => self::PAYMENTS_REFUND, 'group' => 'payments', 'label' => 'Refund payments'],
            ['name' => self::REPORTS_VIEW, 'group' => 'reports', 'label' => 'View reports'],
            ['name' => self::REPORTS_EXPORT, 'group' => 'reports', 'label' => 'Export reports'],
            ['name' => self::SETTINGS_VIEW, 'group' => 'settings', 'label' => 'View settings'],
            ['name' => self::SETTINGS_MANAGE, 'group' => 'settings', 'label' => 'Manage settings'],
            ['name' => self::USERS_VIEW, 'group' => 'users', 'label' => 'View admin users'],
            ['name' => self::USERS_MANAGE, 'group' => 'users', 'label' => 'Manage admin users'],
            ['name' => self::USERS_ASSIGN_ROLES, 'group' => 'users', 'label' => 'Assign roles'],
            ['name' => self::USERS_ASSIGN_SUPER_ADMIN, 'group' => 'users', 'label' => 'Assign super admin'],
            ['name' => self::ROLES_VIEW, 'group' => 'roles', 'label' => 'View roles'],
            ['name' => self::ROLES_MANAGE, 'group' => 'roles', 'label' => 'Manage roles'],
            ['name' => self::PERMISSIONS_VIEW, 'group' => 'permissions', 'label' => 'View permissions'],
            ['name' => self::PERMISSIONS_MANAGE, 'group' => 'permissions', 'label' => 'Manage permissions'],
            ['name' => self::LOGS_VIEW, 'group' => 'logs', 'label' => 'View audit logs'],
        ];
    }
}
