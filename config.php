<?php
/**
 * Konfigurasi Local Drive Manager & SSO Beasiswa Mahaghora
 */

return [
    /**
     * Integrasi Single Sign-On (SSO) Website BMHG
     */
    'sso_enabled' => true,

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
     * Drive dalam daftar ini TIDAK AKAN ditampilkan di web dan DIBLOKIR dari semua akses.
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
