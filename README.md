# EqemuLogin for XenForo 2.3+

EqemuLogin is a specialized bridge for XenForo 2.3+ that allows community administrators to link their forum accounts directly to a private EverQuest Emulator (EQEmu) Login Server. 

This addon provides a centralized interface for players to create, manage, and sync their game accounts without leaving your community site, facilitating a Single Sign-On experience for your private server project.

---

## Key Features

* Account Mapping: Links XenForo User IDs to Login Server account records via a custom forum_name column.
* Self-Service Management: Allows players to update game passwords and manage multiple game accounts from their forum profile.
* Open Source: Fully transparent code for security auditing and community modification.

---

## Requirements

* XenForo: 2.3.0 or higher
* PHP: 8.1+
* Database: Direct SQL connectivity (TCP/IP) from the XenForo web server to the EQEmu Login Server database.

---

## Installation

### 1. Database Preparation
You must modify your existing login_accounts table on your Login Server to allow the forum to track ownership. Run the following SQL query:

```sql
ALTER TABLE login_accounts 
ADD COLUMN forum_name varchar(50) DEFAULT NULL AFTER updated_at;
```

### 2. Upload and Install
1. Upload the contents of the upload folder to your XenForo root directory.
2. Navigate to Admin CP > Add-ons and click Install.
3. Alternatively, use the CLI from your forum root:
   php cmd.php xf-addon:install EqemuLogin

### 3. Configuration
1. Navigate to Admin CP > Setup > Options > Eqemu Login Server.
2. Input your Login Server database credentials (Host, Port, Username, Password, and DB Name).
3. Note: If using Docker, ensure the host IP is accessible (e.g., 172.17.0.1 for host-bridge communication).

---

## Security & Code Review

**Mandatory Code Review:** As a fundamental best practice, administrators should thoroughly review and understand all source code before deploying it to a production environment. By using this software, you acknowledge that you have audited the code and accept full responsibility for its implementation and maintenance.

**Data Protection:** Self-hosting a login server grants full autonomy over player data but places the burden of security on the administrator. Ensure your database is properly firewalled and that connections are encrypted (SSL/TLS) where applicable.

---

## Disclaimer

* As-Is: This software is provided as-is without any warranty of any kind, express or implied.
* Liability: The author is not responsible for any issues, data loss, security breaches, or server-side consequences resulting from the use of this software.
* Compliance: Users must strictly adhere to the EQEmu Terms of Service. The author is not responsible for any actions that result in the termination of a project's standing within the EQEmu community.

---

## License

This project is licensed under the MIT License. See the LICENSE file for the full legal text.

**Note:** The MIT License allows for free use, modification, and redistribution, provided the original copyright notice remains intact.