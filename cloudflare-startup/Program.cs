using System;
using System.Collections.Generic;
using System.Diagnostics;
using System.IO;
using System.Net;
using System.Text;
using System.Text.RegularExpressions;
using System.Threading;

namespace CloudflareTunnelAutostart
{
    class Config
    {
        public string Email { get; set; }
        public string Password { get; set; }
        public string ShortlinkEditUrl { get; set; }
        public string LocalUrl { get; set; }

        public Config()
        {
            Email = "";
            Password = "";
            ShortlinkEditUrl = "https://beasiswamahaghora.com/admin/shortlinks/78/edit";
            LocalUrl = "http://localhost";
        }

        public static string GetConfigPath()
        {
            string appDir = AppDomain.CurrentDomain.BaseDirectory;
            return Path.Combine(appDir, "config.json");
        }

        public static Config Load()
        {
            string path = GetConfigPath();
            Config cfg = new Config();

            if (File.Exists(path))
            {
                try
                {
                    string json = File.ReadAllText(path);
                    cfg.Email = ExtractJsonValue(json, "email");
                    cfg.Password = ExtractJsonValue(json, "password");
                    string editUrl = ExtractJsonValue(json, "shortlink_edit_url");
                    if (!string.IsNullOrEmpty(editUrl)) cfg.ShortlinkEditUrl = editUrl;
                    string loc = ExtractJsonValue(json, "local_url");
                    if (!string.IsNullOrEmpty(loc)) cfg.LocalUrl = loc;
                }
                catch { }
            }

            return cfg;
        }

        public void Save()
        {
            string path = GetConfigPath();
            StringBuilder sb = new StringBuilder();
            sb.AppendLine("{");
            sb.AppendLine(string.Format("  \"email\": \"{0}\",", EscapeJson(Email)));
            sb.AppendLine(string.Format("  \"password\": \"{0}\",", EscapeJson(Password)));
            sb.AppendLine(string.Format("  \"shortlink_edit_url\": \"{0}\",", EscapeJson(ShortlinkEditUrl)));
            sb.AppendLine(string.Format("  \"local_url\": \"{0}\"", EscapeJson(LocalUrl)));
            sb.AppendLine("}");
            File.WriteAllText(path, sb.ToString(), Encoding.UTF8);
        }

        private static string ExtractJsonValue(string json, string key)
        {
            Match m = Regex.Match(json, "\"" + Regex.Escape(key) + "\"\\s*:\\s*\"([^\"]*)\"");
            return m.Success ? m.Groups[1].Value.Replace("\\\"", "\"").Replace("\\\\", "\\") : "";
        }

        private static string EscapeJson(string s)
        {
            if (string.IsNullOrEmpty(s)) return "";
            return s.Replace("\\", "\\\\").Replace("\"", "\\\"");
        }
    }

    class Program
    {
        private static Config config;
        private static bool updateAttempted = false;
        private static readonly object lockObj = new object();
        private static CookieContainer cookieJar = new CookieContainer();

        static void Main(string[] args)
        {
            Console.Title = "Cloudflare Tunnel & BMHG Shortlink Auto-Updater";

            PrintHeader();

            config = Config.Load();

            // Cek jika kredensial admin belum diisi
            if (string.IsNullOrEmpty(config.Email) || string.IsNullOrEmpty(config.Password))
            {
                PromptUserForCredentials();
            }

            // Cari lokasi cloudflared.exe
            string cloudflaredPath = FindCloudflaredExe();
            if (string.IsNullOrEmpty(cloudflaredPath))
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine("\n[ERROR] cloudflared.exe tidak ditemukan di komputer!");
                Console.WriteLine("Silakan install Cloudflared terlebih dahulu.");
                Console.ResetColor();
                Console.WriteLine("\nTekan Enter untuk keluar...");
                Console.ReadLine();
                return;
            }

            Console.ForegroundColor = ConsoleColor.Yellow;
            Console.WriteLine("[INFO] Memulai Cloudflare Tunnel untuk: " + config.LocalUrl);
            Console.WriteLine("[INFO] Akun Admin BMHG: " + config.Email);
            Console.ResetColor();
            Console.WriteLine("------------------------------------------------------------\n");

            StartCloudflaredAndMonitor(cloudflaredPath);
        }

        static void PrintHeader()
        {
            Console.ForegroundColor = ConsoleColor.Cyan;
            Console.WriteLine("============================================================");
            Console.WriteLine("      CLOUDFLARE TUNNEL & AUTO-UPDATE SHORTLINK BMHG        ");
            Console.WriteLine("============================================================");
            Console.ResetColor();
        }

        static void PromptUserForCredentials()
        {
            Console.ForegroundColor = ConsoleColor.Yellow;
            Console.WriteLine("\n[SETUP AWAL] Masukkan akun Admin Beasiswa Mahaghora:");
            Console.WriteLine("(Pengaturan ini disimpan di config.json dan hanya perlu diisi sekali)\n");
            Console.ResetColor();

            Console.Write("Email Admin BMHG   : ");
            config.Email = Console.ReadLine().Trim();

            Console.Write("Password Admin BMHG: ");
            config.Password = ReadPassword();
            Console.WriteLine();

            config.Save();

            Console.ForegroundColor = ConsoleColor.Green;
            Console.WriteLine("\n[OK] Kredensial berhasil disimpan ke config.json!\n");
            Console.ResetColor();
        }

        static string ReadPassword()
        {
            StringBuilder pwd = new StringBuilder();
            while (true)
            {
                ConsoleKeyInfo key = Console.ReadKey(true);
                if (key.Key == ConsoleKey.Enter) break;
                if (key.Key == ConsoleKey.Backspace)
                {
                    if (pwd.Length > 0)
                    {
                        pwd.Remove(pwd.Length - 1, 1);
                        Console.Write("\b \b");
                    }
                }
                else if (!char.IsControl(key.KeyChar))
                {
                    pwd.Append(key.KeyChar);
                    Console.Write("*");
                }
            }
            return pwd.ToString();
        }

        static string FindCloudflaredExe()
        {
            string p86 = @"C:\Program Files (x86)\cloudflared\cloudflared.exe";
            string p64 = @"C:\Program Files\cloudflared\cloudflared.exe";

            if (File.Exists(p86)) return p86;
            if (File.Exists(p64)) return p64;

            try
            {
                Process p = new Process();
                p.StartInfo.FileName = "where";
                p.StartInfo.Arguments = "cloudflared";
                p.StartInfo.UseShellExecute = false;
                p.StartInfo.RedirectStandardOutput = true;
                p.StartInfo.CreateNoWindow = true;
                p.Start();
                string output = p.StandardOutput.ReadLine();
                p.WaitForExit();
                if (!string.IsNullOrEmpty(output) && File.Exists(output.Trim()))
                {
                    return output.Trim();
                }
            }
            catch { }

            return null;
        }

        static void StartCloudflaredAndMonitor(string exePath)
        {
            ProcessStartInfo psi = new ProcessStartInfo
            {
                FileName = exePath,
                Arguments = "tunnel --url " + config.LocalUrl,
                UseShellExecute = false,
                RedirectStandardOutput = true,
                RedirectStandardError = true,
                CreateNoWindow = false
            };

            try
            {
                using (Process proc = new Process())
                {
                    proc.StartInfo = psi;

                    proc.OutputDataReceived += (sender, e) => ProcessLogLine(e.Data);
                    proc.ErrorDataReceived += (sender, e) => ProcessLogLine(e.Data);

                    proc.Start();
                    proc.BeginOutputReadLine();
                    proc.BeginErrorReadLine();

                    proc.WaitForExit();
                }
            }
            catch (Exception ex)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine("\n[ERROR] Gagal menjalankan cloudflared: " + ex.Message);
                Console.ResetColor();
                Console.ReadLine();
            }
        }

        static void ProcessLogLine(string line)
        {
            if (string.IsNullOrEmpty(line)) return;

            Console.WriteLine(line);

            // Deteksi URL trycloudflare.com
            Match m = Regex.Match(line, @"https://[a-zA-Z0-9.-]+\.trycloudflare\.com");
            if (m.Success)
            {
                string tunnelUrl = m.Value.Trim();

                lock (lockObj)
                {
                    if (!updateAttempted)
                    {
                        updateAttempted = true;

                        ThreadPool.QueueUserWorkItem((state) =>
                        {
                            PerformWebsiteUpdate(tunnelUrl);
                        });
                    }
                }
            }
        }

        static void PerformWebsiteUpdate(string tunnelUrl)
        {
            Thread.Sleep(1500);

            Console.ForegroundColor = ConsoleColor.Cyan;
            Console.WriteLine("\n============================================================");
            Console.WriteLine(">>> DETEKSI URL TUNNEL : " + tunnelUrl);
            Console.WriteLine(">>> MENGUPDATE SHORTLINK DI BEASISWAMAHAGHORA.COM...");
            Console.WriteLine("============================================================");
            Console.ResetColor();

            try
            {
                ServicePointManager.SecurityProtocol = SecurityProtocolType.Tls12 | SecurityProtocolType.Tls11 | SecurityProtocolType.Tls;
                ServicePointManager.ServerCertificateValidationCallback = delegate { return true; };

                // 1. GET Login Page
                string loginUrl = "https://beasiswamahaghora.com/login";
                string loginHtml = HttpGet(loginUrl, null);
                string loginCsrf = ExtractAttrValue(loginHtml, "input", "name", "_token", "value");

                if (string.IsNullOrEmpty(loginCsrf))
                {
                    Console.ForegroundColor = ConsoleColor.Red;
                    Console.WriteLine("[ERROR] Gagal mengambil CSRF token dari halaman login.");
                    Console.ResetColor();
                    return;
                }

                // 2. POST Login
                string loginPayload = string.Format("_token={0}&email={1}&password={2}&remember=on",
                    Uri.EscapeDataString(loginCsrf),
                    Uri.EscapeDataString(config.Email),
                    Uri.EscapeDataString(config.Password));

                string loginResp = HttpPost(loginUrl, loginPayload, loginUrl);

                // 3. GET Halaman Edit Shortlink
                string editUrl = config.ShortlinkEditUrl;
                string editHtml = HttpGet(editUrl, "https://beasiswamahaghora.com/admin/shortlinks");

                if (editHtml.Contains("name=\"email\"") && editHtml.Contains("name=\"password\""))
                {
                    Console.ForegroundColor = ConsoleColor.Red;
                    Console.WriteLine("[ERROR] Login Gagal! Periksa email dan password di config.json.");
                    Console.ResetColor();
                    return;
                }

                // 4. Temukan form Edit Shortlink (form yang berisi code / url)
                MatchCollection allForms = Regex.Matches(editHtml, @"<form\b[^>]*action=[""']([^""']*)[""'][^>]*>([\s\S]*?)</form>", RegexOptions.IgnoreCase);
                string formAction = null;
                string formBody = null;

                foreach (Match fm in allForms)
                {
                    string body = fm.Groups[2].Value;
                    if (body.Contains("name=\"code\"") || body.Contains("name=\"url\""))
                    {
                        formAction = fm.Groups[1].Value;
                        formBody = body;
                        break;
                    }
                }

                if (string.IsNullOrEmpty(formAction))
                {
                    formAction = "https://beasiswamahaghora.com/admin/shortlinks/78";
                    formBody = editHtml;
                }

                if (!formAction.StartsWith("http", StringComparison.OrdinalIgnoreCase))
                {
                    Uri baseUri = new Uri(editUrl);
                    formAction = new Uri(baseUri, formAction).ToString();
                }

                Dictionary<string, string> formFields = new Dictionary<string, string>();

                // Parse input tags dari form edit
                MatchCollection inputs = Regex.Matches(formBody, @"<input(?:\s+[^>]*)?>", RegexOptions.IgnoreCase);
                foreach (Match im in inputs)
                {
                    string tag = im.Value;
                    string name = GetAttr(tag, "name");
                    string value = GetAttr(tag, "value");
                    string type = (GetAttr(tag, "type") ?? "text").ToLower();

                    if (string.IsNullOrEmpty(name)) continue;
                    if ((type == "checkbox" || type == "radio") && !tag.ToLower().Contains("checked")) continue;
                    if (type == "submit" || type == "button" || type == "image") continue;

                    formFields[name] = value ?? "";
                }

                // Parse select dropdowns
                MatchCollection selects = Regex.Matches(formBody, @"<select(?:\s+[^>]*)?name=[""']([^""']+)[""'][^>]*>(.*?)</select>", RegexOptions.IgnoreCase | RegexOptions.Singleline);
                foreach (Match sm in selects)
                {
                    string selName = sm.Groups[1].Value;
                    string selBody = sm.Groups[2].Value;
                    Match opt = Regex.Match(selBody, @"<option[^>]*selected[^>]*value=[""']([^""']*)[""']", RegexOptions.IgnoreCase);
                    if (!opt.Success)
                    {
                        opt = Regex.Match(selBody, @"<option[^>]*value=[""']([^""']*)[""']", RegexOptions.IgnoreCase);
                    }
                    if (opt.Success)
                    {
                        formFields[selName] = opt.Groups[1].Value;
                    }
                }

                // Update field URL dan pastikan method PUT
                formFields["url"] = tunnelUrl;
                formFields["_method"] = "PUT";

                if (!formFields.ContainsKey("code") || string.IsNullOrEmpty(formFields["code"]))
                {
                    formFields["code"] = "penyimpananbmhg";
                }

                if (!formFields.ContainsKey("category") || string.IsNullOrEmpty(formFields["category"]))
                {
                    formFields["category"] = "BMHG";
                }

                // Bangun payload update
                StringBuilder payloadBuilder = new StringBuilder();
                foreach (var kv in formFields)
                {
                    if (payloadBuilder.Length > 0) payloadBuilder.Append("&");
                    payloadBuilder.Append(Uri.EscapeDataString(kv.Key));
                    payloadBuilder.Append("=");
                    payloadBuilder.Append(Uri.EscapeDataString(kv.Value));
                }

                // 5. POST / PUT Update ke Website
                string updateResp = HttpPost(formAction, payloadBuilder.ToString(), editUrl);

                Console.ForegroundColor = ConsoleColor.Green;
                Console.WriteLine("\n============================================================");
                Console.WriteLine(" [BERHASIL] Shortlink BMHG Berhasil Diperbarui Otomatis!");
                Console.WriteLine(" Short Link    : bmhg.link/" + formFields["code"]);
                Console.WriteLine(" Kategori      : " + formFields["category"]);
                Console.WriteLine(" URL Asli Baru : " + tunnelUrl);
                Console.WriteLine("============================================================\n");
                Console.ResetColor();
            }
            catch (Exception ex)
            {
                Console.ForegroundColor = ConsoleColor.Red;
                Console.WriteLine("\n[ERROR] Gagal memperbarui website BMHG: " + ex.Message);
                Console.ResetColor();
            }
        }

        static string HttpGet(string url, string referer)
        {
            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.CookieContainer = cookieJar;
            req.Method = "GET";
            req.UserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
            if (!string.IsNullOrEmpty(referer)) req.Referer = referer;
            req.AllowAutoRedirect = true;
            req.Timeout = 15000;

            using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
            using (Stream s = resp.GetResponseStream())
            using (StreamReader reader = new StreamReader(s, Encoding.UTF8))
            {
                return reader.ReadToEnd();
            }
        }

        static string HttpPost(string url, string postData, string referer)
        {
            byte[] bytes = Encoding.UTF8.GetBytes(postData);

            HttpWebRequest req = (HttpWebRequest)WebRequest.Create(url);
            req.CookieContainer = cookieJar;
            req.Method = "POST";
            req.ContentType = "application/x-www-form-urlencoded";
            req.ContentLength = bytes.Length;
            req.UserAgent = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36";
            if (!string.IsNullOrEmpty(referer)) req.Referer = referer;
            req.AllowAutoRedirect = true;
            req.Timeout = 15000;

            using (Stream reqStream = req.GetRequestStream())
            {
                reqStream.Write(bytes, 0, bytes.Length);
            }

            using (HttpWebResponse resp = (HttpWebResponse)req.GetResponse())
            using (Stream s = resp.GetResponseStream())
            using (StreamReader reader = new StreamReader(s, Encoding.UTF8))
            {
                return reader.ReadToEnd();
            }
        }

        static string GetAttr(string tag, string attrName)
        {
            Match m = Regex.Match(tag, attrName + @"=[""']([^""']*)[""']", RegexOptions.IgnoreCase);
            return m.Success ? m.Groups[1].Value : null;
        }

        static string ExtractAttrValue(string html, string tagName, string matchAttr, string matchValue, string targetAttr)
        {
            MatchCollection tags = Regex.Matches(html, @"<" + tagName + @"(?:\s+[^>]*)?>", RegexOptions.IgnoreCase);
            foreach (Match m in tags)
            {
                string tag = m.Value;
                if (GetAttr(tag, matchAttr) == matchValue)
                {
                    return GetAttr(tag, targetAttr);
                }
            }
            return null;
        }
    }
}
