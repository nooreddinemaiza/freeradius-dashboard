<p align="center">
  <img src="Public/Assets/images/logo.png" alt="NM Network Access Manager Logo" width="140">
</p>

<h1 align="center">NM Network Access Manager</h1>

<p align="center">
  🔗 Page du projet : <a href="https://nooreddinemaiza.github.io/projects/nm-network-access-manager.html">nooreddinemaiza.github.io/projects/nm-network-access-manager</a>
</p>

Plateforme web de gestion centralisée des utilisateurs d'un portail captif basée sur FreeRADIUS, développée en PHP orienté objet.

---

## 📌 Présentation

Cette plateforme permet de simplifier et centraliser la gestion des utilisateurs d'un portail captif, en remplaçant les manipulations manuelles dans la base de données FreeRADIUS par une interface web complète, sécurisée et automatisée.

Elle offre une gestion avancée des utilisateurs, groupes, politiques d'accès, ainsi qu'un système de statistiques détaillées sur l'activité réseau.

---

## 🚀 Fonctionnalités principales

### 👤 Gestion des utilisateurs
- Création, modification et suppression des utilisateurs
- Comptes permanents ou temporaires (expirables)
- Activation, suspension ou désactivation des comptes
- Changement sécurisé des mots de passe avec chiffrement

### 👥 Gestion des groupes
- Création et organisation des utilisateurs en groupes
- Application centralisée des politiques d'accès
- Héritage automatique des règles par les membres

### 🛡️ Politiques d'accès
- Création et gestion des règles d'accès réseau
- Application sur utilisateurs ou groupes
- Intégration directe avec FreeRADIUS

### 👨‍💼 Système de rôles
- Administrateur global avec accès total
- Modérateurs avec permissions limitées par groupe
- Gestion des droits fine et sécurisée

### 📊 Statistiques et monitoring
- Consommation upload / download par utilisateur et groupe
- Temps de connexion
- Utilisateurs actifs en temps réel
- Top consommateurs réseau
- Sites les plus visités

---

## 🗂️ Structure du projet

Le dossier **`/Public`** est le **dossier racine (main)** de l'application : c'est lui qui doit être déclaré comme `DocumentRoot` dans la configuration Apache. Tous les autres dossiers (logique métier, scripts, stockage, etc.) se trouvent en dehors de `/Public` et ne doivent **jamais** être exposés directement au navigateur.

```
nm-network-access-manager/
├── Public/                 👉 Dossier racine du site (DocumentRoot)
│   ├── Assets/
│   │   └── images/
│   │       └── logo.png    👉 Logo de la plateforme
│   ├── index.php
│   └── ...
├── Storage/
│   └── Files/               (scripts DNS, fichiers internes)
├── .env                     (configuration de l'application)
└── ...
```

> ⚠️ Toute configuration Apache pointant vers la racine du dépôt (`/var/www/nm-network-access-manager`) au lieu de `/var/www/nm-network-access-manager/Public` exposerait le code source et les fichiers sensibles (`.env`, logique métier) directement sur le web. **Le `DocumentRoot` doit impérativement pointer vers `/Public`.**

---

## 🖥️ Installation et déploiement sur le serveur web

### 0. Prérequis : installation d'Apache2, PHP et des modules nécessaires

Sur une distribution Debian / Ubuntu :

```bash
sudo apt update
sudo apt install -y apache2 mysql-server \
    php php-cli libapache2-mod-php \
    php-mysql php-mbstring php-xml php-curl \
    php-zip php-gd php-bcmath php-intl php-json
```

Modules PHP nécessaires et leur rôle :

| Module | Rôle |
|---|---|
| `php-mysql` (mysqli/pdo_mysql) | Connexion à la base de données FreeRADIUS / MySQL |
| `php-mbstring` | Gestion correcte des chaînes multi-octets (UTF-8, noms, mots de passe) |
| `php-xml` | Traitement XML / DOM (exports, rapports) |
| `php-curl` | Requêtes HTTP sortantes (API de traitement des logs DNS, cron) |
| `php-zip` | Génération d'archives (exports, sauvegardes) |
| `php-gd` | Génération/traitement d'images (statistiques, graphiques) |
| `php-bcmath` | Calculs précis (statistiques de consommation, quotas) |
| `php-intl` | Internationalisation / formats de dates et nombres |
| `php-json` | Échanges de données JSON avec les workers et l'API |

> 💡 Adaptez la commande d'installation avec `dnf`/`yum` (CentOS/RHEL/Fedora) ou `pacman` (Arch) si votre distribution est différente, en utilisant les paquets équivalents (ex : `php-mysqlnd`, `php-mbstring`, etc.).

Vérifiez la version de PHP installée et assurez-vous qu'elle est compatible avec le projet :

```bash
php -v
```

---

### 1. Activation des modules Apache nécessaires

La plateforme nécessite plusieurs modules Apache activés pour fonctionner correctement (réécriture d'URL, en-têtes, etc.) :

```bash
sudo a2enmod rewrite
sudo a2enmod headers
sudo a2enmod php8.3   # adaptez selon votre version de PHP (ex: php8.1, php8.2...)
sudo a2enmod ssl      # recommandé si vous servez la plateforme en HTTPS
sudo a2enmod deflate  # optionnel, compression des réponses HTTP
sudo a2enmod expires  # optionnel, gestion du cache des assets statiques
```

Redémarrez Apache pour appliquer les modules :

```bash
sudo systemctl restart apache2
```

> ⚠️ Le module **`rewrite`** est **obligatoire** : il est utilisé pour le routage des URL de l'application (front controller dans `/Public/index.php`) et pour la gestion des liens d'invitation des modérateurs.

---

### 2. Cloner le dépôt

Clonez ce dépôt directement dans le répertoire racine de votre serveur web :

```bash
cd /var/www/
sudo git clone https://github.com/nooreddinemaiza/nm-network-access-manager.git
```

> Vous pouvez renommer le dossier cloné selon vos préférences ou conserver le nom `nm-network-access-manager`.

---

### 3. Créer un VirtualHost Apache

Créez un fichier de configuration pour votre site. Par exemple :

```bash
sudo nano /etc/apache2/sites-available/nm-network-access-manager.conf
```

Exemple de configuration minimale — notez que le `DocumentRoot` pointe vers le sous-dossier **`Public`**, et non vers la racine du dépôt :

```apache
<VirtualHost *:80>
    ServerName votre-domaine.local
    DocumentRoot /var/www/nm-network-access-manager/Public

    <Directory /var/www/nm-network-access-manager/Public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    # Empêche l'accès direct aux dossiers sensibles en dehors de /Public
    <Directory /var/www/nm-network-access-manager>
        Require all denied
    </Directory>
    <Directory /var/www/nm-network-access-manager/Public>
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/nm-network-access-manager-error.log
    CustomLog ${APACHE_LOG_DIR}/nm-network-access-manager-access.log combined
</VirtualHost>
```

> Un fichier `.htaccess` doit être présent dans `/Public` avec les règles de réécriture d'URL (front controller). Vérifiez que `AllowOverride All` est bien défini pour que ce fichier soit pris en compte.

---

### 4. Activer le site virtuel

Activez le VirtualHost, désactivez le site par défaut si nécessaire, puis rechargez Apache :

```bash
sudo a2ensite nm-network-access-manager.conf
sudo a2dissite 000-default.conf   # optionnel
sudo systemctl reload apache2
```

---

### 5. Attribuer les permissions

Avant d'ouvrir le navigateur, accordez les droits nécessaires à l'utilisateur de votre serveur web sur le dossier du projet. Le nom de cet utilisateur dépend de votre distribution :

- `www-data` sur Debian / Ubuntu
- `apache` sur CentOS / RHEL / Fedora

```bash
sudo chown -R www-data:www-data /var/www/nm-network-access-manager/
sudo find /var/www/nm-network-access-manager/ -type d -exec chmod 2775 {} \;
sudo find /var/www/nm-network-access-manager/ -type f -exec chmod 0664 {} \;
```

> Remplacez `www-data` par `apache` si nécessaire selon votre configuration.

Assurez-vous en particulier que :
- le fichier `.env` est lisible par le serveur web mais **non accessible depuis le navigateur** (il se trouve en dehors de `/Public`) ;
- le dossier `Storage/` (logs, fichiers DNS, uploads) est accessible en écriture par `www-data` / `apache`.

Vous pouvez maintenant accéder à la plateforme depuis votre navigateur, à l'adresse correspondant à votre `ServerName`.

---

## ✅ Récapitulatif : pré-requis pour un site fonctionnel

Avant de considérer l'installation comme terminée, vérifiez la checklist suivante :

- [ ] Apache2 installé et actif (`systemctl status apache2`)
- [ ] PHP installé avec tous les modules requis (`mbstring`, `mysql`, `xml`, `curl`, `zip`, `gd`, `bcmath`, `intl`)
- [ ] Modules Apache `rewrite` et `headers` activés
- [ ] MySQL / MariaDB installé et accessible
- [ ] `DocumentRoot` du VirtualHost pointant vers `/Public`
- [ ] `AllowOverride All` activé pour la prise en compte du `.htaccess` dans `/Public`
- [ ] Fichier `.env` correctement configuré (accès base de données, clés de chiffrement, etc.)
- [ ] Permissions attribuées à `www-data`/`apache` sur l'ensemble du projet, avec droits d'écriture sur `Storage/`
- [ ] Base de données FreeRADIUS existante avec toutes les tables requises (voir section suivante)
- [ ] Extensions de base de données (tables, vues, procédures stockées) créées par la plateforme lors du premier lancement
- [ ] FreeRADIUS reconfiguré pour utiliser `radcheck_view` / `radreply_view`
- [ ] (Optionnel) Scripts DNS installés et tâches cron configurées si les statistiques de sites visités sont souhaitées

---

## ⚙️ Phase de configuration initiale

Avant utilisation, une phase de configuration est obligatoire :

### 1. Base de données FreeRADIUS
La base de données doit contenir les tables suivantes :

- `nas`
- `nasreload`
- `radacct`
- `radcheck`
- `radgroupcheck`
- `radgroupreply`
- `radpostauth`
- `radreply`
- `radusergroup`

⚠️ Si une de ces tables est absente, l'installation sera bloquée.

---

### 2. Extensions de la base de données

La plateforme ajoute automatiquement :
- nouvelles tables
- vues SQL
- procédures stockées
- événements

👉 L'utilisateur MySQL doit avoir les permissions complètes pour créer ces objets.

---

### 3. Permissions système

Le serveur web (Apache) doit avoir les droits sur :

- les fichiers de la plateforme
- le fichier `.env`
- les logs système
- les fichiers de configuration

---

## 📡 Intégration DNS / pfSense (statistiques des sites visités)

La plateforme exploite les logs de résolution DNS pour générer des statistiques sur les sites visités par les utilisateurs du portail captif.

### Prérequis

Vous avez besoin de l'un des deux éléments suivants :
- **pfSense** configuré en tant que serveur DNS (via le résolveur DNS intégré)
- **Un serveur DNS local** dédié (Unbound, BIND, dnsmasq, etc.)

Dans tous les cas, votre serveur DNS doit être configuré pour **envoyer ses logs de résolution vers le serveur web** (via syslog ou toute autre méthode de transfert de logs).

---

### Scripts utilisés

Les scripts suivants se trouvent dans le dossier `/Storage/Files/` du projet.

> ⚠️ **Important :** Copiez ces scripts vers un emplacement sécurisé sur votre système (par exemple `/usr/local/bin/` ou `/opt/scripts/`) et supprimez les originaux du dossier `/Storage/Files/` une fois en place.

```bash
sudo cp /var/www/nm-network-access-manager/Storage/Files/dns_extractor.sh /usr/local/bin/
sudo cp /var/www/nm-network-access-manager/Storage/Files/dns-daily-sync.sh /usr/local/bin/
sudo chmod +x /usr/local/bin/dns_extractor.sh
sudo chmod +x /usr/local/bin/dns-daily-sync.sh
```

- **`dns_extractor.sh`**
  → Extrait les logs DNS du système Linux, les traite et les archive par date.

- **`dns-daily-sync.sh`**
  → Déclenche la synchronisation quotidienne des logs vers la base de données analytique.

---

### Configuration des tâches planifiées (cron)

Ajoutez les deux tâches suivantes à votre crontab (`sudo crontab -e`) :

```cron
# Extraction des logs DNS — toutes les heures (ou selon votre charge réseau)
0 * * * * /usr/local/bin/dns_extractor.sh

# Synchronisation quotidienne — à 01h20 (20 minutes après l'extracteur)
20 1 * * * /usr/local/bin/dns-daily-sync.sh
```

> 💡 **Conseil sur le timing :** Prévoyez un délai raisonnable entre l'exécution de `dns_extractor.sh` et `dns-daily-sync.sh` — au minimum **20 minutes**. Ce délai peut être réduit si votre réseau a peu de clients (et donc peu de résolutions DNS à traiter).

---

### Fichier central

```
pfsense_dns_today.log
```

Ce fichier doit être :
- généré et mis à jour quotidiennement par `dns_extractor.sh`
- accessible en lecture par l'utilisateur Apache (`www-data` ou `apache`)

---

### API de traitement des logs

Le script `dns-daily-sync.sh` envoie des requêtes HTTP pour déclencher les workers de traitement :

```bash
API_URL="http://192.168.0.20/cron/update-log"
```

⚠️ Modifiez cette adresse IP dans le script pour qu'elle corresponde à votre serveur web avant de l'activer.

---

## 🔁 Traitement des logs (Job Workers)

Les logs DNS sont traités via un système de workers :

- traitement en batch
- gestion de gros volumes de données
- optimisation des performances
- insertion en base de données analytique

---

## 🔐 Intégration FreeRADIUS

### Configuration requise :

- FreeRADIUS doit utiliser SQL
- même base de données que la plateforme

### Modification importante :

Par défaut FreeRADIUS utilise :

- `radcheck`
- `radreply`

👉 Avec cette plateforme, remplacer par :

- `radcheck_view`
- `radreply_view`

Dans :

```
/etc/freeradius/3.0/mods-enabled/sql
```

Paramètres :

- `authcheck_table`
- `authreply_table`

---

## 🔒 Sécurité

- Chiffrement complet des mots de passe
- Protection des credentials administrateur et utilisateurs
- Compatibilité PAP pour FreeRADIUS
- Synchronisation avec pfSense
- Isolation du code source grâce au `DocumentRoot` pointant vers `/Public` (le `.env` et la logique métier ne sont jamais exposés au navigateur)

---

## 📈 Avantages de la plateforme

Sans cette solution :

- gestion manuelle des utilisateurs dans FreeRADIUS
- modifications complexes en base de données
- absence de statistiques avancées

Avec la plateforme :

- interface centralisée
- gestion intuitive des utilisateurs
- application simple des politiques
- analyse complète du trafic réseau
- supervision des connexions en temps réel

---

## 👨‍💻 Rôles et permissions

### 🧑 Administrateur

- contrôle total de la plateforme
- gestion des modérateurs
- gestion des groupes et politiques
- création et suppression globale

### 🧑‍💼 Modérateur

- gestion des groupes assignés
- création et gestion des utilisateurs
- création de liens d'invitation
- application de politiques sur ses groupes

---

## 🧠 Objectif du projet

Cette plateforme vise à moderniser et simplifier la gestion des environnements FreeRADIUS en entreprise, hotspot Wi-Fi, ou ISP, en combinant :

- administration réseau
- automatisation
- sécurité
- analyse de données

---

## 🛠️ Stack technique

- PHP (OOP)
- MySQL / MariaDB
- FreeRADIUS
- pfSense
- Apache
- Bash scripting
- Linux server

---

## 👤 Auteur

**Nour-eddine MAIZA**
Développeur web & Administrateur systèmes et réseaux

Passionné par la conception de solutions hybrides combinant développement web et infrastructures réseaux.

🔗 Page du projet : [nooreddinemaiza.github.io/projects/nm-network-access-manager.html](https://nooreddinemaiza.github.io/projects/nm-network-access-manager.html)

---

## 📌 Notes

Cette plateforme est en évolution continue et peut intégrer de nouvelles fonctionnalités.
