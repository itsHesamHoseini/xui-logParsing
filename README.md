# xui-logParsing

A lightweight PHP-based CLI tool designed for **3X-UI VPN administrators** based on xray-core to detect and monitor clients who exceed the maximum allowed simultaneous connections.  
It parses the `access.log` file, provides clear and colorful CLI reports, and optionally sends violation alerts directly to a Telegram bot.

---

## ✨ Features
- Parse and analyze `/usr/local/x-ui/access.log`
- Detect clients with more than the allowed concurrent connections
- Beautiful, colorized CLI output for better readability
- Telegram bot integration for instant violation alerts
- Overlap interval detection to identify suspicious connection windows
- Smart filtering of IPs to avoid false positives (unique client range detection)

---

## ⚙️ Requirements
- **PHP ≥ 8.0**
- An active **3X-UI** installation with `access.log` available at:
`/usr/local/x-ui/access.log`


---

## 📦 Installation
1. Clone the repository:
 ```bash
 git clone https://github.com/itsHesamHoseini/xui-logParsing.git
 cd xui-logParsing
 ```

2. Create a .env file in the project root with the following variables:
 ```env
API_KEY_TOKEN=your_telegram_bot_token
FROM_ID=your_admin_numeric_id
MAX_ALLOW_USER=2
 ```

3.Run the script:
 ```bash
php FindAllConnectionIPS.php
 ```

📊 Example Output:
<img width="1143" height="679" alt="image" src="https://github.com/user-attachments/assets/7bc525c6-b77d-420b-8244-8bcb1db9e2dd" />

