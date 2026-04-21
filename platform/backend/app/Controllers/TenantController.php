<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Database;
use App\Core\Request;
use App\Core\Response;
use App\Core\Validator;

/**
 * Tenant settings REST controller.
 *
 * Manages per-tenant configuration including general settings and branding.
 */
final class TenantController
{
    private Database $db;

    public function __construct()
    {
        $this->db = Database::getInstance();
    }

    /**
     * GET /api/tenant/settings
     *
     * Retrieve current tenant settings.
     */
    public function getSettings(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $tenant = $this->db->fetchOne(
            'SELECT id, slug, name, status, created_at, updated_at FROM tenants WHERE id = ?',
            [$tenantId],
        );

        if ($tenant === null) {
            return Response::error('Tenant not found', 404);
        }

        // Fetch tenant settings (key-value)
        $rows = $this->db->fetchAll(
            'SELECT setting_key, setting_value FROM tenant_settings WHERE tenant_id = ?',
            [$tenantId],
        );

        $settings = [];
        foreach ($rows as $row) {
            $decoded = json_decode($row['setting_value'], true);
            $settings[$row['setting_key']] = $decoded ?? $row['setting_value'];
        }

        return Response::ok([
            'tenant'   => $tenant,
            'settings' => $settings,
        ]);
    }

    /**
     * PUT /api/tenant/settings
     *
     * Update tenant settings. Accepts an object of key-value pairs.
     */
    public function updateSettings(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $validator = new Validator($request->all(), [
            'name'                => 'nullable|string|max:255',
            'timezone'            => 'nullable|string|max:50',
            'currency'            => 'nullable|string|max:3',
            'locale'              => 'nullable|string|max:10',
            'fiscal_year_start'   => 'nullable|date',
            'savings_rate'        => 'nullable|numeric',
            'checking_rate'       => 'nullable|numeric',
            'loan_base_rate'      => 'nullable|numeric',
            'cd_rate'             => 'nullable|numeric',
            'min_password_length' => 'nullable|integer',
            'password_expiry'     => 'nullable|integer',
            'require_uppercase'   => 'nullable|boolean',
            'require_special'     => 'nullable|boolean',
            'require_2fa'         => 'nullable|boolean',
            'session_timeout'     => 'nullable|integer',
            'rate_limit'          => 'nullable|integer',
            'max_login_attempts'  => 'nullable|integer',
            'lockout_duration'    => 'nullable|integer',
            'network_enabled'       => 'nullable|boolean',
            'network_router_mode'   => 'nullable|boolean',
            'network_node_code'     => 'nullable|string|max:20',
            'network_node_name'     => 'nullable|string|max:255',
            'network_endpoint_url'  => 'nullable|string|max:512',
            'network_transfer_fee'  => 'nullable|numeric',
            'network_fee_type'      => 'nullable|string|max:20',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        // Update tenant name if provided
        if (isset($data['name'])) {
            $this->db->execute(
                'UPDATE tenants SET name = ?, updated_at = NOW() WHERE id = ?',
                [$data['name'], $tenantId],
            );
            unset($data['name']);
        }

        // Upsert each setting
        foreach ($data as $key => $value) {
            $encoded = is_string($value) ? $value : json_encode($value);

            $exists = $this->db->fetchOne(
                'SELECT id FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?',
                [$tenantId, $key],
            );

            if ($exists) {
                $this->db->execute(
                    'UPDATE tenant_settings SET setting_value = ?, updated_at = NOW()
                     WHERE tenant_id = ? AND setting_key = ?',
                    [$encoded, $tenantId, $key],
                );
            } else {
                $this->db->query(
                    'INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value, created_at, updated_at)
                     VALUES (UUID(), ?, ?, ?, NOW(), NOW())',
                    [$tenantId, $key, $encoded],
                );
            }
        }

        // Return refreshed settings
        return $this->getSettings($request);
    }

    /**
     * PUT /api/tenant/branding
     *
     * Update tenant branding (logo URL, colours, etc.).
     */
    public function updateBranding(Request $request): Response
    {
        $tenantId = $request->getAttribute('tenant_id');

        $validator = new Validator($request->all(), [
            'logo_url'        => 'nullable|string|max:512',
            'favicon_url'     => 'nullable|string|max:512',
            'primary_color'   => 'nullable|string|max:7',
            'secondary_color' => 'nullable|string|max:7',
            'accent_color'    => 'nullable|string|max:7',
            'tagline'         => 'nullable|string|max:255',
            'support_email'   => 'nullable|email|max:255',
            'support_phone'   => 'nullable|string|max:20',
            'website_url'     => 'nullable|string|max:512',
        ]);

        if ($validator->fails()) {
            return Response::validationError($validator->errors());
        }

        $data = $validator->validated();

        if (empty($data)) {
            return Response::error('No branding fields to update', 422);
        }

        // Store branding as tenant_settings with a "branding_" prefix
        foreach ($data as $key => $value) {
            $settingKey = "branding_{$key}";
            $encoded    = is_string($value) ? $value : json_encode($value);

            $exists = $this->db->fetchOne(
                'SELECT id FROM tenant_settings WHERE tenant_id = ? AND setting_key = ?',
                [$tenantId, $settingKey],
            );

            if ($exists) {
                $this->db->execute(
                    'UPDATE tenant_settings SET setting_value = ?, updated_at = NOW()
                     WHERE tenant_id = ? AND setting_key = ?',
                    [$encoded, $tenantId, $settingKey],
                );
            } else {
                $this->db->query(
                    'INSERT INTO tenant_settings (id, tenant_id, setting_key, setting_value, created_at, updated_at)
                     VALUES (UUID(), ?, ?, ?, NOW(), NOW())',
                    [$tenantId, $settingKey, $encoded],
                );
            }
        }

        // Return the full branding object
        $rows = $this->db->fetchAll(
            "SELECT setting_key, setting_value FROM tenant_settings
             WHERE tenant_id = ? AND setting_key LIKE 'branding_%'",
            [$tenantId],
        );

        $branding = [];
        foreach ($rows as $row) {
            $key = str_replace('branding_', '', $row['setting_key']);
            $branding[$key] = $row['setting_value'];
        }

        return Response::ok(['branding' => $branding], 'Branding updated successfully');
    }
}
