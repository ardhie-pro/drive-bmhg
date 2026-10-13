<?php
/**
 * Konfigurasi Local Drive Manager & SSO Beasiswa Mahaghora
 */

return [
    /**
     * Integrasi Single Sign-On (SSO) Website BMHG
     */
    // Explicit opt-in applies only to direct localhost development requests.
    'sso_enabled' => !(in_array(PHP_SAPI, ['cli-server', 'apache2handler'], true)
        && getenv('DRIVE_LOCAL_DEV') === '1'
        && in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
        && in_array(strtolower($_SERVER['HTTP_HOST'] ?? ''), ['127.0.0.1:8085', 'localhost:8085'], true)),

    /**
     * URL Website BMHG
     * Menggunakan server produksi resmi: https://beasiswamahaghora.com
     * (Atau ganti ke 'http://websitebmhg.test' jika ingin pengujian full lokal)
     */
    'sso_server_url' => 'https://beasiswamahaghora.com',

    /**
     * Endpoint Verifikasi Token SSO
     */
    'sso_verify_url' => 'https://beasiswamahaghora.com/sso/verify',

    /**
     * Role / Status pengguna yang diizinkan mengakses drive
     * Default: ['admin', 'beswan']
     */
    'allowed_roles' => ['admin', 'beswan'],

    /**
     * Daftar Drive yang DIKECUALIKAN / DISEMBUNYIKAN:
     * Drive C: disembunyikan agar user hanya membuka Flashdisk / Drive data lainnya.
     */
    'excluded_drives' => ['C:'],

    /**
     * Sembunyikan folder & file sistem Windows ($RECYCLE.BIN, System Volume Information, dsb)
     */
    'hide_system_files' => true,

    /**
     * Daftar nama folder/file sistem yang otomatis diabaikan
     */
    'hidden_system_names' => [
        '$RECYCLE.BIN',
        'System Volume Information',
        'pagefile.sys',
        'hiberfil.sys',
        'swapfile.sys',
        'DumpStack.log'
    ]
];
