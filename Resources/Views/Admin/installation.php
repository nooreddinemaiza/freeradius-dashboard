<pre>
<?php
echo "</pre>";

use Core\Helper\AssetHelper;

$stepMap     = [0 => 1, 1 => 2, 15 => 3, 17 => 4, 2 => 5];
$total_steps = 5;
$displayStep = $stepMap[$step] ?? $step;
$titre = "";
$form_action = "/installation";
switch ($step) {
    case 0:
        $titre = "Bienvenue & Pré-requis";
        break;
    case 1:
        $titre = "Configuration de la base de données";
        $form_action .= "/database";
        break;
    case 2:
        $titre = "Création de l'administrateur";
        $form_action .= "/administrator";
        if (isset($data['root_detected']) and $data['root_detected']) $form_action .= "/empty";
        break;
    case 15:
        $titre = "Vérification & Migration de la base de données";
        $form_action .= "/migration";
        break;
    case 17:
        $titre = "Configuration des clients NAS";
        $form_action .= "/nas";
        break;
    default:
        $titre = "Étape inconnue";
        break;
}

$view->layout('layouts', 'main.php');
$view->section('content');
?>
<div class="min-h-screen bg-white dark:bg-zinc-900 overflow-hidden flex items-center justify-center p-4">
    <div class="w-full" style="max-width: 760px;">
        <?php if ($step == 0): ?>
            <div class="welcome-wrap">
                <!-- En-tête -->
                <div style="text-align:center; padding: 2rem 0 1.5rem;">
                    <div class="logo-container">
                        <?= $logo_url ?></div>
                    <h2 style="font-size:2rem;font-weight:800;
                               margin:0 0 2.6rem;">
                        <?= $name ?>
                    </h2>
                    <div style="display:inline-flex;align-items:center;gap:.6rem;
                                background:#161b22;border:1px solid #30363d;
                                border-radius:9999px;padding:.4rem 1rem;margin-bottom:1.2rem;">
                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944
                                     a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0
                                     5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03
                                     9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                        <span style="font-size:.78rem;font-weight:600;color:#93c5fd;letter-spacing:.04em;">
                            INSTALLATION GUIDÉE
                        </span>
                    </div>
                    <p style="color:#8b949e;font-size:.95rem;max-width:480px;margin:0 auto;">
                        Plateforme de gestion centralisée des utilisateurs FreeRADIUS.
                        Avant de démarrer, lisez attentivement les pré-requis ci-dessous.
                    </p>
                </div>

                <!-- Steps overview -->
                <div style="display:flex;gap:.5rem;justify-content:center;margin-bottom:2rem;flex-wrap:wrap;">
                    <?php
                    $steps_overview = [
                        ['1', 'Pré-requis',      'pill-blue'],
                        ['2', 'Base de données', 'pill-blue'],
                        ['3', 'Migration',       'pill-blue'],
                        ['4', 'Client NAS',      'pill-blue'],
                        ['5', 'Administrateur',  'pill-blue'],
                    ];
                    foreach ($steps_overview as $s):
                    ?>
                        <span class="pill <?= $s[2] ?>">
                            <span style="opacity:.6">Étape <?= $s[0] ?></span>
                            <?= $s[1] ?>
                        </span>
                    <?php endforeach; ?>
                </div>

                <form action="" method="POST" id="welcomeForm">
                    <input type="hidden" name="csrf_token" value="<?= $csrf_token ?? '' ?>">

                    <?php if (!empty($errors['checking'])): ?>
                        <div class="req-card" style="border-color:#ef4444;margin-bottom:1rem;">
                            <?php foreach ($errors['checking'] as $e): ?>
                                <p style="color:#fca5a5;font-size:.85rem;margin:.2rem 0;"><?= $e ?></p>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <!-- ── 1. À propos de la plateforme ── -->
                    <div class="req-card" style="margin-bottom:1rem;">
                        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;">
                            <div style="width:2rem;height:2rem;border-radius:.5rem;
                                        background:#1d3461;display:flex;align-items:center;
                                        justify-content:center;flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                </svg>
                            </div>
                            <h3 style="font-size:1rem;font-weight:700;color:#e6edf3;margin:0;">
                                À propos de la plateforme
                            </h3>
                        </div>
                        <p style="color:#8b949e;font-size:.88rem;line-height:1.65;margin:0 0 .8rem;">
                            Cette plateforme simplifie la gestion des utilisateurs d'un portail captif basé sur
                            <strong style="color:#93c5fd;">FreeRADIUS</strong>.
                            Sans elle, toute création ou modification d'utilisateur se fait manuellement dans la base de données.
                            Avec elle, vous pouvez :
                        </p>
                        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:.6rem;">
                            <?php
                            $features = [
                                ['👤', 'Créer des comptes permanents ou expirables'],
                                ['🔐', 'Gérer les mots de passe chiffrés'],
                                ['📂', 'Regrouper les utilisateurs en groupes'],
                                ['⚙️', 'Appliquer des politiques d\'accès'],
                                ['📊', 'Consulter les statistiques (upload, download, temps de connexion)'],
                                ['🌐', 'Visualiser les sites visités (via logs DNS)'],
                                ['👥', 'Gérer des modérateurs par groupe'],
                                ['🔑', 'Un administrateur root unique avec droits totaux'],
                            ];
                            foreach ($features as $f): ?>
                                <div style="display:flex;align-items:flex-start;gap:.5rem;
                                        background:#0d1117;border:1px solid #21262d;
                                        border-radius:.6rem;padding:.55rem .75rem;">
                                    <span style="font-size:.9rem;flex-shrink:0;"><?= $f[0] ?></span>
                                    <span style="color:#8b949e;font-size:.8rem;line-height:1.5;"><?= $f[1] ?></span>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- ── 2. Pré-requis système ── -->
                    <div class="req-card" style="margin-bottom:1rem;">
                        <div style="display:flex;align-items:center;gap:.75rem;margin-bottom:1rem;">
                            <div style="width:2rem;height:2rem;border-radius:.5rem;
                                        background:#2e1065;display:flex;align-items:center;
                                        justify-content:center;flex-shrink:0;">
                                <svg width="16" height="16" fill="none" stroke="#8b5cf6" stroke-width="2" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M9 3H5a2 2 0 00-2 2v4m6-6h10a2 2 0 012 2v4M9 3v18
                                             m0 0h10a2 2 0 002-2V9M9 21H5a2 2 0 01-2-2V9m0 0h18" />
                                </svg>
                            </div>
                            <h3 style="font-size:1rem;font-weight:700;color:#e6edf3;margin:0;">
                                Pré-requis système
                            </h3>
                            <span class="pill pill-yellow">À vérifier avant de continuer</span>
                        </div>

                        <div class="chk-row">
                            <div class="chk-icon" style="background:#14532d;">
                                <svg width="12" height="12" fill="none" stroke="#22c55e" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <div>
                                <p style="margin:0;font-size:.87rem;font-weight:600;color:#e6edf3;">
                                    FreeRADIUS installé et configuré
                                </p>
                                <p style="margin:.2rem 0 0;font-size:.8rem;color:#8b949e;">
                                    La plateforme s'appuie sur la base de données de FreeRADIUS.
                                    FreeRADIUS doit être opérationnel avant de démarrer la configuration.
                                </p>
                            </div>
                        </div>

                        <div class="chk-row">
                            <div class="chk-icon" style="background:#14532d;">
                                <svg width="12" height="12" fill="none" stroke="#22c55e" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            </div>
                            <div>
                                <p style="margin:0;font-size:.87rem;font-weight:600;color:#e6edf3;">
                                    Tables FreeRADIUS créées dans la base de données
                                </p>
                                <p style="margin:.2rem 0 0;font-size:.8rem;color:#8b949e;">
                                    Les tables suivantes doivent exister.
                                    Leur absence <strong style="color:#fca5a5;">bloquera la migration</strong> à l'étape suivante.
                                </p>
                                <div style="display:flex;flex-wrap:wrap;gap:.35rem;margin-top:.5rem;">
                                    <?php foreach (
                                        [
                                            'nas',
                                            'nasreload',
                                            'radacct',
                                            'radcheck',
                                            'radgroupcheck',
                                            'radgroupreply',
                                            'radpostauth',
                                            'radreply',
                                            'radusergroup'
                                        ] as $t
                                    ): ?>
                                        <span class="ci"><?= $t ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>

                        <div class="chk-row">
                            <div class="chk-icon" style="background:#451a03;">
                                <svg width="12" height="12" fill="none" stroke="#f59e0b" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667
                                             1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464
                                             0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div>
                                <p style="margin:0;font-size:.87rem;font-weight:600;color:#e6edf3;">
                                    Droits de l'utilisateur de base de données
                                </p>
                                <p style="margin:.2rem 0 0;font-size:.8rem;color:#8b949e;">
                                    L'utilisateur DB doit avoir les droits <span class="ci">CREATE TABLE</span>,
                                    <span class="ci">CREATE VIEW</span>, <span class="ci">CREATE PROCEDURE</span>
                                    et <span class="ci">CREATE EVENT</span> pour que la migration réussisse.
                                </p>
                            </div>
                        </div>

                        <div class="chk-row">
                            <div class="chk-icon" style="background:#451a03;">
                                <svg width="12" height="12" fill="none" stroke="#f59e0b" stroke-width="2.5" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667
                                             1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464
                                             0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                                </svg>
                            </div>
                            <div>
                                <p style="margin:0;font-size:.87rem;font-weight:600;color:#e6edf3;">
                                    Permissions Apache sur les fichiers de la plateforme
                                </p>
                                <p style="margin:.2rem 0 0;font-size:.8rem;color:#8b949e;">
                                    L'utilisateur <span class="ci">www-data</span> (ou <span class="ci">apache</span>)
                                    doit pouvoir lire et écrire dans les fichiers
                                    <span class="ci">.env</span>, les logs et les fichiers de configuration.
                                <div>
                                    <h3 class="m-2">
                                        Exemple de commades à executer :
                                    </h3>
                                    <p class="ci m-2">sudo chown -R www-data:www-data /var/www/html/</p>
                                    <p class="ci m-2">
                                        sudo chmod -R 2775 /var/www/html/
                                    </p>
                                </div>

                                </p>
                            </div>
                        </div>
                    </div>

                    <!-- ── 3. Configuration FreeRADIUS ── -->
                    <details class="req-card" style="margin-bottom:1rem;">
                        <summary>
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div style="display:flex;align-items:center;gap:.75rem;">
                                    <div style="width:2rem;height:2rem;border-radius:.5rem;
                                                background:#0c1a2e;display:flex;align-items:center;
                                                justify-content:center;flex-shrink:0;">
                                        <svg width="16" height="16" fill="none" stroke="#3b82f6" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724
                                                     1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37
                                                     2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756
                                                     2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94
                                                     1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572
                                                     1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724
                                                     0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724
                                                     1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924
                                                     0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31
                                                     2.37-2.37.996.608 2.296.07 2.572-1.065z" />
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                        </svg>
                                    </div>
                                    <span style="font-size:1rem;font-weight:700;color:#e6edf3;">
                                        Configuration FreeRADIUS requise
                                    </span>
                                </div>
                                <svg class="acc-arrow" width="16" height="16" fill="none" stroke="#8b949e"
                                    stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </summary>

                        <div style="margin-top:1rem;border-top:1px solid #30363d;padding-top:1rem;
                                    display:flex;flex-direction:column;gap:.85rem;">

                            <div style="background:#0d1117;border:1px solid #21262d;border-radius:.6rem;padding:.9rem 1rem;">
                                <p style="margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:#93c5fd;">
                                    1. Activer le module SQL dans FreeRADIUS
                                </p>
                                <p style="margin:0;font-size:.8rem;color:#8b949e;line-height:1.6;">
                                    Assurez-vous que FreeRADIUS utilise le module SQL pointant vers
                                    <strong style="color:#e6edf3;">la même base de données</strong> que celle configurée ici.
                                </p>
                            </div>

                            <div style="background:#0d1117;border:1px solid #21262d;border-radius:.6rem;padding:.9rem 1rem;">
                                <p style="margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:#93c5fd;">
                                    2. Utiliser les vues de la plateforme
                                </p>
                                <p style="margin:0 0 .6rem;font-size:.8rem;color:#8b949e;line-height:1.6;">
                                    Par défaut FreeRADIUS lit <span class="ci">radcheck</span> et <span class="ci">radreply</span>.
                                    Après la migration, changez ces paramètres dans
                                    <span class="ci">/etc/chemin_vers_freeradius/mods-enabled/sql</span> :
                                </p>
                                <div style="font-family:monospace;font-size:.78rem;
                                            background:#161b22;border:1px solid #30363d;
                                            border-radius:.5rem;padding:.7rem .9rem;color:#a5d6ff;">
                                    authcheck_table = <span style="color:#86efac;">radcheck_view</span><br>
                                    authreply_table = <span style="color:#86efac;">radreply_view</span>
                                </div>
                            </div>

                            <div style="background:#0d1117;border:1px solid #21262d;border-radius:.6rem;padding:.9rem 1rem;">
                                <p style="margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:#93c5fd;">
                                    3. Activer PAP pour le déchiffrement des mots de passe dans <span class="ci">Pfsense</span>
                                </p>
                                <p style="margin:0;font-size:.8rem;color:#8b949e;line-height:1.6;">
                                    La plateforme chiffre tous les mots de passe. FreeRADIUS et pfSense doivent
                                    utiliser <strong style="color:#e6edf3;">PAP</strong> pour les déchiffrer correctement.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- ── 4. Statistiques sites visités (DNS) ── -->
                    <details class="req-card" style="margin-bottom:1rem;">
                        <summary>
                            <div style="display:flex;align-items:center;justify-content:space-between;">
                                <div style="display:flex;align-items:center;gap:.75rem;">
                                    <div style="width:2rem;height:2rem;border-radius:.5rem;
                                                background:#0c2318;display:flex;align-items:center;
                                                justify-content:center;flex-shrink:0;">
                                        <svg width="16" height="16" fill="none" stroke="#22c55e" stroke-width="2" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round"
                                                d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9
                                                     0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657
                                                     0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9" />
                                        </svg>
                                    </div>
                                    <span style="font-size:1rem;font-weight:700;color:#e6edf3;">
                                        Statistiques DNS / Sites visités
                                    </span>
                                    <span class="pill pill-green">Optionnel</span>
                                </div>
                                <svg class="acc-arrow" width="16" height="16" fill="none" stroke="#8b949e"
                                    stroke-width="2" viewBox="0 0 24 24" style="flex-shrink:0;">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                                </svg>
                            </div>
                        </summary>

                        <div style="margin-top:1rem;border-top:1px solid #30363d;padding-top:1rem;
                                    display:flex;flex-direction:column;gap:.85rem;">

                            <p style="margin:0;font-size:.83rem;color:#8b949e;line-height:1.65;">
                                La fonctionnalité <strong style="color:#e6edf3;">Sites visités</strong>
                                repose sur les logs DNS envoyés par pfSense (ou votre serveur DNS) au serveur web.
                                Elle est <strong style="color:#86efac;">optionnelle</strong> — le reste de la plateforme fonctionne sans elle.
                            </p>

                            <div style="background:#0d1117;border:1px solid #21262d;border-radius:.6rem;padding:.9rem 1rem;">
                                <p style="margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:#86efac;">
                                    Script <span class="ci">dns_extractor.sh</span>
                                </p>
                                <p style="margin:0;font-size:.8rem;color:#8b949e;line-height:1.6;">
                                    Extrait les logs DNS des journaux système Linux et les archive par date.
                                </p>
                            </div>

                            <div style="background:#0d1117;border:1px solid #21262d;border-radius:.6rem;padding:.9rem 1rem;">
                                <p style="margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:#86efac;">
                                    Script <span class="ci">dns-daily-sync.sh</span> — planifié en cron
                                </p>
                                <p style="margin:0 0 .5rem;font-size:.8rem;color:#8b949e;line-height:1.6;">
                                    Traite les logs quotidiennement et génère <span class="ci">pfsense_dns_today.log</span>,
                                    lisible par Apache.
                                    Ce script envoie aussi des requêtes HTTP pour déclencher les workers de la plateforme.
                                    Modifiez l'URL dans le script :
                                </p>
                                <div style="font-family:monospace;font-size:.78rem;
                                            background:#161b22;border:1px solid #30363d;
                                            border-radius:.5rem;padding:.6rem .9rem;color:#a5d6ff;">
                                    API_URL="http://<span style="color:#fcd34d;">votre-domaine-ou-ip</span>/cron/update-log"
                                </div>
                            </div>

                            <div style="background:#0d1117;border:1px solid #21262d;border-radius:.6rem;padding:.9rem 1rem;">
                                <p style="margin:0 0 .4rem;font-size:.85rem;font-weight:600;color:#86efac;">
                                    Accès au fichier de logs par Apache
                                </p>
                                <p style="margin:0;font-size:.8rem;color:#8b949e;line-height:1.6;">
                                    Le fichier <span class="ci">pfsense_dns_today.log</span> doit être accessible
                                    en lecture par l'utilisateur Apache. La plateforme traite ce fichier
                                    par lots via un système de <strong style="color:#e6edf3;">Job Workers</strong>.
                                </p>
                            </div>
                        </div>
                    </details>

                    <!-- ── Résumé étapes ── -->
                    <div class="req-card" style="margin-bottom:1.5rem;">
                        <p style="font-size:.8rem;font-weight:700;color:#8b949e;
                                  letter-spacing:.07em;text-transform:uppercase;margin:0 0 .9rem;">
                            Déroulement de l'installation
                        </p>
                        <div style="display:flex;flex-direction:column;gap:.5rem;">
                            <?php
                            $install_steps = [
                                ['1', 'Présentation & pré-requis', '(cette page)', 'pill-blue'],
                                ['2', 'Connexion à la base de données', 'Renseignez les identifiants MySQL/MariaDB', 'pill-blue'],
                                ['3', 'Vérification & Migration', 'Contrôle des tables RADIUS, création des entités applicatives', 'pill-violet'],
                                ['4', 'Configuration du client NAS', 'Ajout du client NAS (pfSense) — un seul client suffit pour cette version', 'pill-blue'],
                                ['5', 'Compte administrateur', 'Création du compte root unique', 'pill-blue'],
                            ];
                            foreach ($install_steps as $s): ?>
                                <div style="display:flex;align-items:flex-start;gap:.75rem;
                                        padding:.55rem .7rem;border-radius:.6rem;
                                        background:#0d1117;border:1px solid #21262d;">
                                    <span class="pill <?= $s[3] ?>" style="flex-shrink:0;margin-top:.1rem;">
                                        Étape <?= $s[0] ?> / <?= $total_steps ?>
                                    </span>
                                    <div>
                                        <p style="margin:0;font-size:.85rem;font-weight:600;color:#e6edf3;"><?= $s[1] ?></p>
                                        <p style="margin:.15rem 0 0;font-size:.78rem;color:#8b949e;"><?= $s[2] ?></p>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <!-- CTA -->
                    <button type="submit" class="btn btn-cta bg-green-400" form="welcomeForm">
                        <svg width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                        </svg>
                        Démarrer la configuration
                    </button>
                </form>
            </div>
            <!-- FIN ÉTAPE 0 -->
        <?php else: ?>
            <div class="w-full max-w-2xl mx-auto">
                <div class="relative bg-white dark:bg-zinc-900 border border-zinc-200 dark:border-zinc-700/60 rounded-2xl shadow-xl shadow-zinc-900/5 dark:shadow-black/30 overflow-hidden">

                    <!-- Accent bar top -->
                    <div class="h-1 w-full bg-gradient-to-r from-indigo-500 via-violet-500 to-purple-500"></div>

                    <div class="p-8 md:p-10">

                        <?php if ($message == 1): ?>
                            <!-- ── Message : configuration terminée ── -->
                            <div class="flex flex-col items-center text-center py-6 gap-4">
                                <div class="w-14 h-14 rounded-2xl bg-red-50 dark:bg-red-950/40 flex items-center justify-center ring-1 ring-red-200 dark:ring-red-800/50">
                                    <svg class="w-7 h-7 text-red-500 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                            d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div>
                                    <h3 class="text-lg font-semibold text-zinc-900 dark:text-zinc-100">Configuration terminée</h3>
                                    <p class="mt-1 text-sm text-zinc-500 dark:text-zinc-400">La configuration est terminée ou l'étape est inconnue.</p>
                                </div>
                            </div>

                        <?php else: ?>
                            <!-- ── Header ── -->
                            <div class="mb-8">
                                <h1 class="text-2xl font-bold tracking-tight text-zinc-900 dark:text-zinc-100">
                                    <?= $titre ?>
                                </h1>
                                <div class="mt-3 flex items-center gap-2">
                                    <div class="h-0.5 w-8 rounded-full bg-indigo-500"></div>
                                    <div class="h-0.5 w-3 rounded-full bg-violet-400 opacity-60"></div>
                                    <div class="h-0.5 w-1.5 rounded-full bg-purple-400 opacity-30"></div>
                                </div>
                            </div>

                            <!-- ── Erreur globale ── -->
                            <?php if (!empty($errors['checking'])): ?>
                                <div class="mb-6 flex gap-3 rounded-xl bg-red-50 dark:bg-red-950/30 border border-red-200 dark:border-red-800/50 p-4">
                                    <svg class="w-5 h-5 text-red-500 dark:text-red-400 flex-shrink-0 mt-0.5" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd"
                                            d="M8.257 3.099c.765-1.36 2.722-1.36 3.486 0l5.58 9.92c.75 1.334-.213 2.98-1.742 2.98H4.42c-1.53 0-2.493-1.646-1.743-2.98l5.58-9.92zM11 13a1 1 0 11-2 0 1 1 0 012 0zm-1-8a1 1 0 00-1 1v3a1 1 0 002 0V6a1 1 0 00-1-1z"
                                            clip-rule="evenodd" />
                                    </svg>
                                    <div class="space-y-0.5">
                                        <?php foreach ($errors['checking'] as $error): ?>
                                            <p class="text-sm font-medium text-red-700 dark:text-red-300"><?= $error ?? "" ?></p>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                            <form action="<?= $form_action ?>" method="POST" class="space-y-5" id="configForm"
                                <?= ($step == 1) ? 'x-data="formConfig()" @submit.prevent="validateForm"' : '' ?>>
                                <input type="hidden" name="csrf_token" value="<?= $csrf_token ?? '' ?>">

                                <?php if ($step == 1): ?>
                                    <!-- ── Étape 1 : Base de données ── -->
                                    <?php
                                    $fields = [
                                        ['db_host',     'Hôte',                '127.0.0.1', 'text',     'required', 'server'],
                                        ['db_port',     'Port',                '3306',      'text',     'required', 'hashtag'],
                                        ['db_database', 'Nom de la base',      '',          'text',     'required', 'database'],
                                        ['db_username', 'Utilisateur',         '',          'text',     'required', 'user'],
                                        ['db_password', 'Mot de passe',        '',          'password', '',         'lock-closed'],
                                        ['db_charset',  'Encodage',            'utf8mb4',   'text',     '',         'globe'],
                                    ];
                                    $icons = [
                                        'server'      => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-2-4h.01M17 16h.01"/>',
                                        'hashtag'     => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M7 20l4-16m2 16l4-16M6 9h14M4 15h14"/>',
                                        'database'    => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4m0 5c0 2.21-3.582 4-8 4s-8-1.79-8-4"/>',
                                        'user'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
                                        'lock-closed' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
                                        'globe'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M21 12a9 9 0 01-9 9m9-9a9 9 0 00-9-9m9 9H3m9 9a9 9 0 01-9-9m9 9c1.657 0 3-4.03 3-9s-1.343-9-3-9m0 18c-1.657 0-3-4.03-3-9s1.343-9 3-9m-9 9a9 9 0 019-9"/>',
                                    ];
                                    ?>
                                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                        <?php foreach ($fields as $field): ?>
                                            <?php
                                            [$id, $label, $placeholder, $type, $required, $icon] = $field;
                                            $value    = htmlspecialchars($_POST[$id] ?? $placeholder, ENT_QUOTES, 'UTF-8');
                                            $fullSpan = in_array($id, ['db_charset', 'db_collation', 'db_prefix']);
                                            ?>
                                            <div class="<?= $fullSpan ? 'md:col-span-2' : '' ?>">
                                                <label for="<?= $id ?>"
                                                    class="block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-1.5">
                                                    <?= $label ?><?= $required ? ' <span class="text-indigo-500 ml-0.5">*</span>' : '' ?>
                                                </label>
                                                <div class="relative">
                                                    <input
                                                        type="<?= $id === 'db_password' ? 'password' : $type ?>"
                                                        id="<?= $id ?>"
                                                        name="<?= $id ?>"
                                                        x-model="<?= str_replace('.', '_', $id) ?>"
                                                        <?php if ($required): ?>@blur="validateField('<?= $id ?>')" <?php endif; ?>
                                                        value="<?= $value ?>"
                                                        class="w-full px-4 py-2.5 pl-10 text-sm rounded-xl
                                                   bg-zinc-50 dark:bg-zinc-800
                                                   border border-zinc-200 dark:border-zinc-700
                                                   text-zinc-900 dark:text-zinc-100
                                                   placeholder-zinc-400 dark:placeholder-zinc-500
                                                   focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 dark:focus:border-indigo-400
                                                   transition duration-150"
                                                        placeholder="<?= $placeholder ?>"
                                                        <?= $required ?>
                                                        <?php if ($id === 'db_password'): ?>x-bind:type="showDbPassword ? 'text' : 'password'" <?php endif; ?> />

                                                    <!-- Icône gauche -->
                                                    <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                        <svg class="w-4 h-4 text-zinc-400 dark:text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                            <?= $icons[$icon] ?? $icons['server'] ?>
                                                        </svg>
                                                    </div>

                                                    <?php if ($id === 'db_password'): ?>
                                                        <!-- Toggle visibilité -->
                                                        <button type="button" @click="showDbPassword = !showDbPassword"
                                                            class="absolute inset-y-0 right-0 flex items-center pr-3 text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300 transition-colors">
                                                            <svg x-show="!showDbPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                            </svg>
                                                            <svg x-show="showDbPassword" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21" />
                                                            </svg>
                                                        </button>
                                                    <?php endif; ?>
                                                </div>

                                                <!-- Erreurs serveur -->
                                                <?php if (isset($errors[$id])): ?>
                                                    <?php foreach ($errors[$id] as $error): ?>
                                                        <p class="mt-1 text-xs text-red-500 dark:text-red-400"><?= $error ?></p>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <!-- Erreur Alpine -->
                                                <p x-show="errors['<?= $id ?>']" x-text="errors['<?= $id ?>']"
                                                    class="mt-1 text-xs text-red-500 dark:text-red-400"></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <script>
                                        function formConfig() {
                                            return {
                                                showDbPassword: false,
                                                errors: {},
                                                db_host: '<?= htmlspecialchars($_POST["db_host"]     ?? "127.0.0.1", ENT_QUOTES, "UTF-8") ?>',
                                                db_port: '<?= htmlspecialchars($_POST["db_port"]     ?? "3306",      ENT_QUOTES, "UTF-8") ?>',
                                                db_database: '<?= htmlspecialchars($_POST["db_database"] ?? "",          ENT_QUOTES, "UTF-8") ?>',
                                                db_username: '<?= htmlspecialchars($_POST["db_username"] ?? "",          ENT_QUOTES, "UTF-8") ?>',
                                                db_password: '<?= htmlspecialchars($_POST["db_password"] ?? "",          ENT_QUOTES, "UTF-8") ?>',
                                                db_charset: '<?= htmlspecialchars($_POST["db_charset"]  ?? "utf8mb4",   ENT_QUOTES, "UTF-8") ?>',

                                                validateField(field) {
                                                    let isValid = true;
                                                    if (field === 'db_host' && !this.db_host) {
                                                        this.errors.db_host = "L'hôte est requis";
                                                        isValid = false;
                                                    }
                                                    if (field === 'db_port' && !this.db_port) {
                                                        this.errors.db_port = "Le port est requis";
                                                        isValid = false;
                                                    }
                                                    if (field === 'db_database' && !this.db_database) {
                                                        this.errors.db_database = "Le nom de la base est requis";
                                                        isValid = false;
                                                    }
                                                    if (field === 'db_username' && !this.db_username) {
                                                        this.errors.db_username = "Le nom d'utilisateur est requis";
                                                        isValid = false;
                                                    }
                                                    return isValid;
                                                },

                                                validateForm() {
                                                    let isValid = true;
                                                    this.errors = {};
                                                    <?php if ($step == 1): ?>
                                                        if (!this.db_host) {
                                                            this.errors.db_host = "L'hôte est requis";
                                                            isValid = false;
                                                        }
                                                        if (!this.db_port) {
                                                            this.errors.db_port = "Le port est requis";
                                                            isValid = false;
                                                        }
                                                        if (!this.db_database) {
                                                            this.errors.db_database = "Le nom de la base est requis";
                                                            isValid = false;
                                                        }
                                                        if (!this.db_username) {
                                                            this.errors.db_username = "Le nom d'utilisateur est requis";
                                                            isValid = false;
                                                        }
                                                    <?php endif; ?>
                                                    if (isValid) {
                                                        document.getElementById('configForm').submit();
                                                    }
                                                }
                                            }
                                        }
                                    </script>

                                <?php elseif ($step == 2): ?>
                                    <!-- ── Étape 2 : Administrateur ── -->
                                     <?php if ($data['root_detected']): ?>
                                        
                  
  <div class="mx-auto max-w-2xl">

    <details class="group mb-4 overflow-hidden rounded-xl border border-gray-200 bg-white shadow-sm dark:border-gray-800 dark:bg-gray-900" open>

      <summary class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 select-none">
        <div class="flex min-w-0 items-center gap-3">
          <div class="flex h-9 w-9 flex-shrink-0 items-center justify-center rounded-lg bg-red-50 dark:bg-red-500/10">
            <svg class="h-4.5 w-4.5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
              <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
            </svg>
          </div>
          <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
              <span class="text-base font-semibold text-gray-900 dark:text-gray-100">
                Compte administrateur unique
              </span>
              <span class="rounded-full bg-red-50 px-2.5 py-0.5 text-xs font-semibold text-red-700 ring-1 ring-inset ring-red-200 dark:bg-red-500/10 dark:text-red-400 dark:ring-red-500/20">
                Obligatoire
              </span>
            </div>
            <p class="mt-0.5 text-xs text-gray-500 dark:text-gray-400">
              Des comptes root existants ont été détectés sur cette instance
            </p>
          </div>
        </div>
        <svg class="h-4 w-4 flex-shrink-0 text-gray-400 transition-transform duration-200 group-open:rotate-90" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
          <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
        </svg>
      </summary>

      <div class="flex flex-col gap-3 border-t border-gray-100 px-4 pb-4 pt-4 dark:border-gray-800">

        <p class="m-0 rounded-lg bg-red-50 px-3.5 py-3 text-sm leading-relaxed text-red-700 ring-1 ring-inset ring-red-100 dark:bg-red-500/10 dark:text-red-300 dark:ring-red-500/20">
          Pour des raisons de <strong class="font-semibold">sécurité</strong>, la plateforme n'autorise qu'un seul compte administrateur de type
          <strong class="font-semibold">root</strong>.
        </p>

        <div class="flex items-start gap-3 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3.5 dark:border-gray-800 dark:bg-gray-800/50">
          <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-500 dark:text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
          </svg>
          <p class="m-0 text-sm font-medium text-gray-800 dark:text-gray-200">
            Un ou plusieurs comptes <span class="font-semibold text-red-600 dark:text-red-400">root</span> existent déjà sur cette installation.
          </p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3.5 dark:border-gray-800 dark:bg-gray-800/50">
          <p class="m-0 text-sm font-semibold text-gray-900 dark:text-gray-100">
            Supprimez ces comptes avant d'en créer un nouveau
          </p>
          <p class="m-0 mt-1.5 text-[0.8rem] leading-relaxed text-gray-500 dark:text-gray-400">
            Si ces comptes proviennent d'une installation précédente, nous vous recommandons de les supprimer :
            ils bloquent la validation de la création du nouveau compte. Le premier compte détecté sera utilisé comme compte root.
          </p>
        </div>

        <div class="flex items-start gap-3 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3.5 dark:border-amber-500/20 dark:bg-amber-500/10">
          <svg class="mt-0.5 h-4 w-4 flex-shrink-0 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
          </svg>
          <p class="m-0 text-[0.83rem] leading-relaxed text-amber-800 dark:text-amber-300">
            Nous déconseillons de <strong class="font-semibold">passer cette étape</strong> sans supprimer les comptes existants pour une installation propre.
            Réutiliser un compte issu d'une autre installation peut provoquer des erreurs de connexion
            (identifiants oubliés, chiffrement du mot de passe incompatible entre versions...).
          </p>
        </div>

        <div class="flex flex-col-reverse items-stretch justify-end gap-2.5 pt-1 sm:flex-row sm:items-center">
          <button type="button" onclick="skipDialog.showModal()"
            class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 hover:text-gray-900 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800 dark:hover:text-gray-100">
            Passer cette étape
          </button>
          <button type="button" onclick="deleteDialog.showModal()"
            class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-red-500 focus-visible:ring-offset-2 dark:focus-visible:ring-offset-gray-900">
            Supprimer les comptes root
          </button>
        </div>

      </div>
    </details>
  </div>

  <!-- Dialogue de confirmation : Passer l'étape -->
  <dialog id="skipDialog" class="m-auto w-full max-w-sm rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-gray-900/50 dark:border-gray-800 dark:bg-gray-900 dark:backdrop:bg-black/70">
      <div class="flex items-start gap-3 p-3">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-amber-50 dark:bg-amber-500/10">
          <svg class="h-5 w-5 text-amber-600 dark:text-amber-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
          </svg>
        </div>
        <div>
          <h2 class="m-0 text-base font-semibold text-gray-900 dark:text-gray-100">
            Passer sans supprimer les comptes ?
          </h2>
          <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Les comptes root existants resteront actifs. Cela peut entraîner des erreurs de connexion
            si un mot de passe a été chiffré avec une version différente de la plateforme.
          </p>
        </div>
      </div>
      <div class="flex justify-end gap-2.5 p-3">
        <button value="cancel" type="button" onclick="skipDialog.close()" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">
          Annuler
        </button>
        <a href="/installation/administrator/pass"
          class="rounded-md bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-amber-600">
          Confirmer et passer
        </a>
      </div>
  </dialog>

  <!-- Dialogue de confirmation : Suppression des comptes root -->
  <dialog id="deleteDialog" class="m-auto w-full max-w-sm rounded-xl border border-gray-200 bg-white p-0 shadow-xl backdrop:bg-gray-900/50 dark:border-gray-800 dark:bg-gray-900 dark:backdrop:bg-black/70">
      <div class="flex items-start gap-3 p-3">
        <div class="flex h-10 w-10 flex-shrink-0 items-center justify-center rounded-full bg-red-50 dark:bg-red-500/10">
          <svg class="h-5 w-5 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
          </svg>
        </div>
        <div>
          <h2 class="m-0 text-base font-semibold text-gray-900 dark:text-gray-100">
            Supprimer définitivement les comptes root ?
          </h2>
          <p class="mt-1.5 text-sm leading-relaxed text-gray-500 dark:text-gray-400">
            Cette action est irréversible. Tous les comptes root existants seront supprimés
            pour permettre la création d'un nouveau compte administrateur.
          </p>
        </div>
      </div>
      <div class="flex justify-end gap-2.5 p-3">
        <button value="cancel"  type="button" onclick="deleteDialog.close()" class="rounded-md border border-gray-300 px-4 py-2 text-sm font-medium text-gray-600 transition-colors hover:bg-gray-100 dark:border-gray-700 dark:text-gray-400 dark:hover:bg-gray-800">
          Annuler
        </button>
        <button type="submit"
          class="rounded-md bg-red-600 px-4 py-2 text-sm font-semibold text-white shadow-sm transition-colors hover:bg-red-700">
          Oui, supprimer
        </button>
      </div>
  </dialog>
                                     <?php else: ?>
                                    <div class="space-y-5" x-data="adminConfig()">

                                        <?php
                                            $adminFields = [
                                                [
                                                    'id'          => 'fullname',
                                                    'label'       => 'Nom complet',
                                                    'type'        => 'text',
                                                    'placeholder' => 'Ex. Noureddine Ma..',
                                                    'model'       => 'fullname',
                                                    'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M5.121 17.804A13.937 13.937 0 0112 16c2.5 0 4.847.655 6.879 1.804M15 10a3 3 0 11-6 0 3 3 0 016 0zm6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>',
                                                ],
                                                [
                                                    'id'          => 'email',
                                                    'label'       => 'Email',
                                                    'type'        => 'email',
                                                    'placeholder' => 'admin@example.com',
                                                    'model'       => 'email',
                                                    'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M16 12a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/>',
                                                ],
                                                [
                                                    'id'          => 'password',
                                                    'label'       => 'Mot de passe',
                                                    'type'        => 'password',
                                                    'placeholder' => 'Mot de passe sécurisé',
                                                    'model'       => 'password',
                                                    'toggle'      => 'showPassword',
                                                    'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
                                                ],
                                                [
                                                    'id'          => 'password_confirm',
                                                    'label'       => 'Confirmer le mot de passe',
                                                    'type'        => 'password',
                                                    'placeholder' => 'Retapez le mot de passe',
                                                    'model'       => 'passwordConfirm',
                                                    'toggle'      => 'showPasswordConfirm',
                                                    'icon'        => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 15v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2zm10-10V7a4 4 0 00-8 0v4h8z"/>',
                                                ],
                                            ];
                                        ?>

                                        <?php foreach ($adminFields as $f): ?>
                                            <div>
                                                <label for="<?= $f['id'] ?>"
                                                    class="block text-xs font-semibold uppercase tracking-wide text-zinc-500 dark:text-zinc-400 mb-1.5">
                                                    <?= $f['label'] ?> <span class="text-indigo-500 ml-0.5">*</span>
                                                </label>
                                                <div class="relative">
                                                    <?php if (isset($f['toggle'])): ?>
                                                        <input x-bind:type="<?= $f['toggle'] ?> ? 'text' : 'password'"
                                                            <?php else: ?>
                                                            <input type="<?= $f['type'] ?>"
                                                            <?php endif; ?>
                                                            id="<?= $f['id'] ?>"
                                                            name="<?= $f['id'] ?>"
                                                            x-model="<?= $f['model'] ?>"
                                                            @blur="validateField('<?= $f['id'] ?>')"
                                                            value="<?= htmlspecialchars($_POST[$f['id']] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                                                            required
                                                            class="w-full px-4 py-2.5 pl-10 <?= isset($f['toggle']) ? 'pr-10' : '' ?> text-sm rounded-xl
                                                       bg-zinc-50 dark:bg-zinc-800
                                                       border border-zinc-200 dark:border-zinc-700
                                                       text-zinc-900 dark:text-zinc-100
                                                       placeholder-zinc-400 dark:placeholder-zinc-500
                                                       focus:outline-none focus:ring-2 focus:ring-indigo-500/40 focus:border-indigo-500 dark:focus:border-indigo-400
                                                       transition duration-150"
                                                            placeholder="<?= $f['placeholder'] ?>" />

                                                        <!-- Icône gauche -->
                                                        <div class="pointer-events-none absolute inset-y-0 left-0 flex items-center pl-3">
                                                            <svg class="w-4 h-4 text-zinc-400 dark:text-zinc-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <?= $f['icon'] ?>
                                                            </svg>
                                                        </div>

                                                        <?php if (isset($f['toggle'])): ?>
                                                            <!-- Toggle visibilité -->
                                                            <button type="button" @click="<?= $f['toggle'] ?> = !<?= $f['toggle'] ?>"
                                                                class="absolute inset-y-0 right-0 flex items-center pr-3 text-zinc-400 hover:text-zinc-600 dark:text-zinc-500 dark:hover:text-zinc-300 transition-colors">
                                                                <svg x-show="!<?= $f['toggle'] ?>" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                </svg>
                                                                <svg x-show="<?= $f['toggle'] ?>" class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L3 3m6.878 6.878L21 21" />
                                                                </svg>
                                                            </button>
                                                        <?php endif; ?>
                                                </div>

                                                <!-- Erreurs serveur -->
                                                <?php if (isset($errors[$f['id']])): ?>
                                                    <?php foreach ($errors[$f['id']] as $error): ?>
                                                        <p class="mt-1 text-xs text-red-500 dark:text-red-400"><?= $error ?></p>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                                <!-- Erreur Alpine -->
                                                <?php $dotKey = str_replace('_', '.', $f['id'] === 'password_confirm' ? 'password_confirm' : $f['id']); ?>
                                                <p x-show="errors.<?= $f['model'] ?>" x-text="errors.<?= $f['model'] ?>"
                                                    class="mt-1 text-xs text-red-500 dark:text-red-400"></p>
                                            </div>
                                        <?php endforeach; ?>
                                    </div>

                                    <script>
                                        function adminConfig() {
                                            return {
                                                fullname: '<?= htmlspecialchars($_POST["fullname"] ?? "", ENT_QUOTES, "UTF-8") ?>',
                                                email: '<?= htmlspecialchars($_POST["email"] ?? "", ENT_QUOTES, "UTF-8") ?>',
                                                password: '<?= htmlspecialchars($_POST["password"] ?? "", ENT_QUOTES, "UTF-8") ?>',
                                                passwordConfirm: '<?= htmlspecialchars($_POST["password_confirm"] ?? "", ENT_QUOTES, "UTF-8") ?>',
                                                showPassword: false,
                                                showPasswordConfirm: false,
                                                errors: {},
                                                validateField(field) {
                                                    let isValid = true;
                                                    if (field === 'fullname' && !this.fullname) {
                                                        this.errors.fullname = "Le nom complet est requis";
                                                        isValid = false;
                                                    }
                                                    if (field === 'email' && !this.email) {
                                                        this.errors.email = "L'email est requis";
                                                        isValid = false;
                                                    } else if (field === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(this.email)) {
                                                        this.errors.email = "Adresse email invalide";
                                                        isValid = false;
                                                    }
                                                    if (field === 'password' && !this.password) {
                                                        this.errors.password = "Le mot de passe est requis";
                                                        isValid = false;
                                                    } else if (field === 'password' && this.password.length < 8) {
                                                        this.errors.password = "Minimum 8 caractères";
                                                        isValid = false;
                                                    }
                                                    if (field === 'password_confirm' && this.password !== this.passwordConfirm) {
                                                        this.errors.password_confirm = "Les mots de passe ne correspondent pas";
                                                        isValid = false;
                                                    }
                                                    return isValid;
                                                }
                                            }
                                        }
                                    </script>
                                    <?php endif; ?>


                                <?php elseif ($step == 15):
                                    $preview = $data['data']['preview'] ?? null; ?>
                                    <!-- ── Étape 1.5 : Migration ── -->
                                    <div class="space-y-4">
                                        <?php if ($preview): ?>

                                            <!-- Bloc RADIUS -->
                                            <?php $radiusOk = $preview['radius']['complete']; ?>
                                            <div class="rounded-xl border <?= $radiusOk
                                                                                ? 'border-emerald-200 dark:border-emerald-800/50 bg-emerald-50 dark:bg-emerald-950/30'
                                                                                : 'border-red-200 dark:border-red-800/50 bg-red-50 dark:bg-red-950/30' ?> p-5">
                                                <div class="flex items-start gap-3 mb-3">
                                                    <div class="w-8 h-8 rounded-lg flex items-center justify-center flex-shrink-0
                                            <?= $radiusOk
                                                ? 'bg-emerald-100 dark:bg-emerald-900/40'
                                                : 'bg-red-100 dark:bg-red-900/40' ?>">
                                                        <?php if ($radiusOk): ?>
                                                            <svg class="w-4 h-4 text-emerald-600 dark:text-emerald-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7" />
                                                            </svg>
                                                        <?php else: ?>
                                                            <svg class="w-4 h-4 text-red-600 dark:text-red-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 8v4m0 4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                            </svg>
                                                        <?php endif; ?>
                                                    </div>
                                                    <div>
                                                        <h3 class="text-sm font-semibold <?= $radiusOk
                                                                                                ? 'text-emerald-800 dark:text-emerald-300'
                                                                                                : 'text-red-800 dark:text-red-300' ?>">
                                                            <?= $radiusOk ? 'Tables RADIUS détectées' : 'Tables RADIUS manquantes — migration bloquée' ?>
                                                        </h3>
                                                        <?php if (!$radiusOk): ?>
                                                            <p class="mt-0.5 text-xs text-red-600 dark:text-red-400">
                                                                Ces tables doivent être créées par FreeRADIUS <strong>avant</strong> de continuer.
                                                            </p>
                                                        <?php endif; ?>
                                                    </div>
                                                </div>
                                                <div class="flex flex-wrap gap-1.5">
                                                    <?php
                                                    $tags = $radiusOk ? $preview['radius']['found'] : $preview['radius']['missing'];
                                                    foreach ($tags as $t): ?>
                                                        <span class="px-2.5 py-1 text-xs font-mono rounded-lg
                                                <?= $radiusOk
                                                            ? 'bg-white dark:bg-emerald-900/30 text-emerald-700 dark:text-emerald-300 border border-emerald-200 dark:border-emerald-700/50'
                                                            : 'bg-white dark:bg-red-900/30 text-red-700 dark:text-red-300 border border-red-200 dark:border-red-700/50' ?>">
                                                            <?= htmlspecialchars($t) ?>
                                                        </span>
                                                    <?php endforeach; ?>
                                                </div>
                                            </div>

                                            <?php if ($radiusOk): ?>

                                                <!-- Tables à créer -->
                                                <?php if (!empty($preview['app_tables']['to_create'])): ?>
                                                    <div class="rounded-xl border border-indigo-200 dark:border-indigo-800/50 bg-indigo-50 dark:bg-indigo-950/30 p-5">
                                                        <div class="flex items-center gap-3 mb-3">
                                                            <div class="w-8 h-8 rounded-lg bg-indigo-100 dark:bg-indigo-900/40 flex items-center justify-center flex-shrink-0">
                                                                <svg class="w-4 h-4 text-indigo-600 dark:text-indigo-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M12 4v16m8-8H4" />
                                                                </svg>
                                                            </div>
                                                            <h3 class="text-sm font-semibold text-indigo-800 dark:text-indigo-300">
                                                                <?= count($preview['app_tables']['to_create']) ?> table(s) à créer
                                                            </h3>
                                                        </div>
                                                        <div class="flex flex-wrap gap-1.5">
                                                            <?php foreach ($preview['app_tables']['to_create'] as $t): ?>
                                                                <span class="px-2.5 py-1 text-xs font-mono rounded-lg bg-white dark:bg-indigo-900/30 text-indigo-700 dark:text-indigo-300 border border-indigo-200 dark:border-indigo-700/50">
                                                                    <?= htmlspecialchars($t) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Vues à créer -->
                                                <?php if (!empty($preview['app_views']['to_create'])): ?>
                                                    <div class="rounded-xl border border-violet-200 dark:border-violet-800/50 bg-violet-50 dark:bg-violet-950/30 p-5">
                                                        <div class="flex items-center gap-3 mb-3">
                                                            <div class="w-8 h-8 rounded-lg bg-violet-100 dark:bg-violet-900/40 flex items-center justify-center flex-shrink-0">
                                                                <svg class="w-4 h-4 text-violet-600 dark:text-violet-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z" />
                                                                </svg>
                                                            </div>
                                                            <h3 class="text-sm font-semibold text-violet-800 dark:text-violet-300">
                                                                <?= count($preview['app_views']['to_create']) ?> vue(s) à créer
                                                            </h3>
                                                        </div>
                                                        <div class="flex flex-wrap gap-1.5">
                                                            <?php foreach ($preview['app_views']['to_create'] as $v): ?>
                                                                <span class="px-2.5 py-1 text-xs font-mono rounded-lg bg-white dark:bg-violet-900/30 text-violet-700 dark:text-violet-300 border border-violet-200 dark:border-violet-700/50">
                                                                    <?= htmlspecialchars($v) ?>
                                                                </span>
                                                            <?php endforeach; ?>
                                                        </div>
                                                    </div>
                                                <?php endif; ?>

                                                <!-- Tout est en place -->
                                                <?php if (empty($preview['app_tables']['to_create']) && empty($preview['app_views']['to_create'])): ?>
                                                    <div class="rounded-xl border border-emerald-200 dark:border-emerald-800/50 bg-emerald-50 dark:bg-emerald-950/30 p-5 text-center">
                                                        <p class="text-sm font-medium text-emerald-700 dark:text-emerald-300">
                                                            ✅ Toutes les tables et vues sont déjà en place.
                                                        </p>
                                                    </div>
                                                <?php endif; ?>

                                            <?php endif; ?>

                                        <?php else: ?>
                                            <p class="text-sm text-zinc-400 dark:text-zinc-500 text-center py-4">
                                                Impossible de charger l'aperçu du schéma.
                                            </p>
                                        <?php endif; ?>
                                    </div>

                                    <!-- Bouton conditionnel migration -->
                                    <?php $hide_default_button = true; ?>
                                    <div class="pt-6">
                                        <?php if ($preview && $preview['radius']['complete']): ?>
                                            <button type="submit"
                                                class="w-full flex items-center justify-center gap-2 py-3 px-6 rounded-xl text-sm font-semibold
                                           bg-indigo-600 hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400
                                           text-white shadow-sm shadow-indigo-900/20
                                           focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-zinc-900
                                           transition duration-150 active:scale-[.98]">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                                        d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4" />
                                                </svg>
                                                Lancer la migration
                                            </button>
                                        <?php else: ?>
                                            <a href="/installation/migration"
                                                class="w-full flex items-center justify-center gap-2 py-3 px-6 rounded-xl text-sm font-semibold
                                           bg-zinc-100 hover:bg-zinc-200 dark:bg-zinc-800 dark:hover:bg-zinc-700
                                           text-zinc-700 dark:text-zinc-300
                                           focus:outline-none focus:ring-2 focus:ring-zinc-400 focus:ring-offset-2 dark:focus:ring-offset-zinc-900
                                           transition duration-150">
                                                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75"
                                                        d="M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15" />
                                                </svg>
                                                Vérifier à nouveau
                                            </a>
                                        <?php endif; ?>
                                    </div>

                                <?php elseif ($step == 17): $hide_default_button = true; ?>
                                    <div class="space-y-5">

                                        <p class="text-sm text-zinc-500 dark:text-zinc-400">
                                            Ajoutez ici les équipements NAS (pfSense) qui pointeront vers cette plateforme.
                                            Le lien du portail captif est généré automatiquement à partir du code de zone,
                                            mais reste modifiable si nécessaire.
                                        </p>

                                        <!-- Liste des NAS déjà ajoutés -->
                                        <?php if (!empty($data['data']['nas_list'])): ?> 
                                            <div class="rounded-xl border border-zinc-200 dark:border-zinc-700 overflow-hidden">
                                                <table class="w-full text-sm">
                                                    <thead class="bg-zinc-50 dark:bg-zinc-800 text-xs uppercase text-zinc-500">
                                                        <tr>
                                                            <th class="text-left px-4 py-2">Nom</th>
                                                            <th class="text-left px-4 py-2">Adresse IP / domaine</th>
                                                            <th class="text-left px-4 py-2">Zone / Lien portail</th>
                                                            <th class="text-left px-4 py-2">Port</th>
                                                            <th class="text-left px-4 py-2">
                                                                <span class="p-1.5rounded" title="Supprimer">
                                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                    <path fill-rule="evenodd"
                                                                        d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
                                                                        clip-rule="evenodd" />
                                                                </svg>
                                                                </span>
                                                            </th>
                                                            
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php foreach ($data['data']['nas_list'] as $nas): ?>
                                                            <tr class="border-t border-zinc-100 dark:border-zinc-800">
                                                                <td class="px-4 py-2"><?= htmlspecialchars($nas['name']) ?></td>
                                                                <td class="px-4 py-2"><?= htmlspecialchars($nas['ip_address']) ?></td>
                                                                <td class="px-4 py-2 truncate max-w-[220px]"><?= htmlspecialchars($nas['zone_name']) ?></td>
                                                                <td class="px-4 py-2"><?= htmlspecialchars((string)$nas['port']) ?></td>
                                                                <td class="px-4 py-2">
                                                                    <a href="?delete_nas=<?= htmlspecialchars((string)$nas['id']) ?>"
                                                                        class="p-1.5 text-gray-600 hover:text-red-600  dark:text-gray-400 dark:hover:text-red-400 rounded transition-colors"
                                                                        title="Supprimer">
                                                                        <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20">
                                                                            <path fill-rule="evenodd"
                                                                                d="M9 2a1 1 0 00-.894.553L7.382 4H4a1 1 0 000 2v10a2 2 0 002 2h8a2 2 0 002-2V6a1 1 0 100-2h-3.382l-.724-1.447A1 1 0 0011 2H9zM7 8a1 1 0 012 0v6a1 1 0 11-2 0V8zm5-1a1 1 0 00-1 1v6a1 1 0 102 0V8a1 1 0 00-1-1z"
                                                                                clip-rule="evenodd" />
                                                                        </svg>
                                                                    </a>
                                                            </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    </tbody>
                                                </table>
                                            </div>
                                        <?php endif; ?>

                                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                            <div>
                                                <label class="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Nom du NAS <span class="text-red-600">*</span></label>
                                                <input type="text" name="name" placeholder="Ex. NAS-ISTA-01"
                                                    class="w-full px-4 py-2.5 text-sm rounded-xl bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">
                                                    Adresse IP ou nom de domaine <span class="text-red-600">*</span>
                                                </label>
                                                <input type="text" name="ip_address" id="nas_ip_address" placeholder="192.168.1.1 ou nas.example.com"
                                                    class="w-full px-4 py-2.5 text-sm rounded-xl bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Port</label>
                                                <input type="number" name="port" id="nas_port" placeholder="8002"
                                                    class="w-full px-4 py-2.5 text-sm rounded-xl bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700">
                                            </div>

                                            <div>
                                                <label class="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">Code de zone (pfSense)<span class="text-red-600">*</span></label>
                                                <input type="text" id="nas_zone_code" placeholder="ista"
                                                    class="w-full px-4 py-2.5 text-sm rounded-xl bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700">
                                                <p class="mt-1 text-xs text-zinc-400">Génère automatiquement le lien ci-dessous.</p>
                                            </div>

                                            <div class="md:col-span-2">
                                                <label class="block text-xs font-semibold uppercase text-zinc-500 mb-1.5">
                                                    Lien du portail captif <span class="text-red-600">*</span>
                                                </label>
                                                <input type="text" name="zone_name" id="nas_zone_name"
                                                    placeholder="http://pfsense.idosr.net:8002/index.php?zone=ista"
                                                    class="w-full px-4 py-2.5 text-sm rounded-xl bg-zinc-50 dark:bg-zinc-800 border border-zinc-200 dark:border-zinc-700 font-mono">
                                                <p class="mt-1 text-xs text-zinc-400">
                                                    Généré automatiquement, mais modifiable manuellement si besoin.
                                                </p>
                                            </div>
                                        </div>

                                        <?php if (!empty($errors['name']) || !empty($errors['ip_address']) || !empty($errors['zone_name'])): ?>
                                            <?php foreach (['name', 'ip_address', 'zone_name', 'port'] as $f): ?>
                                                <?php if (!empty($errors[$f])): ?>
                                                    <?php foreach ($errors[$f] as $e): ?>
                                                        <p class="text-xs text-red-500"><?= $e ?></p>
                                                    <?php endforeach; ?>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <button type="submit"
                                            class="w-full py-2.5 rounded-xl text-sm font-semibold bg-indigo-600 hover:bg-indigo-500 text-white">
                                            + Ajouter ce NAS
                                        </button>

                                        <!-- Continuer -->
                                        <a href="/installation/nas/continue"
                                            class="w-full py-3 rounded-xl text-sm font-semibold bg-emerald-600 hover:bg-emerald-500 text-white">
                                            Continuer vers la création de l'administrateur
                                        </a>
                                    </div>

                                    <script>
                                        (function() {
                                            const ipInput = document.getElementById('nas_ip_address');
                                            const portInput = document.getElementById('nas_port');
                                            const zoneCode = document.getElementById('nas_zone_code');
                                            const zoneName = document.getElementById('nas_zone_name');

                                            if (!ipInput || !portInput || !zoneCode || !zoneName) return;

                                            function buildZoneLink() {
                                                const ip = ipInput.value.trim() || 'ip_addresse_ou_nomdomain';
                                                const port = portInput.value.trim() || 'port';
                                                const zone = zoneCode.value.trim() || 'nom_zone';
                                                zoneName.value = 'http://' + ip + ':' + port + '/index.php?zone=' + zone;
                                            }

                                            ipInput.addEventListener('input', buildZoneLink);
                                            portInput.addEventListener('input', buildZoneLink);
                                            zoneCode.addEventListener('input', buildZoneLink);
                                        })();
                                    </script>
                                <?php endif; ?>

                                <!-- ── Bouton Continuer par défaut ── -->
                                 
                                <?php if (!$data['root_detected'] && ($step ?? 1) !== 15 && empty($hide_default_button)): ?>
                                    <div class="pt-6">
                                        <button type="submit"
                                            class="w-full flex items-center justify-center gap-2 py-3 px-6 rounded-xl text-sm font-semibold
                                       bg-indigo-600 hover:bg-indigo-500 dark:bg-indigo-500 dark:hover:bg-indigo-400
                                       text-white shadow-sm shadow-indigo-900/20
                                       focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:ring-offset-2 dark:focus:ring-offset-zinc-900
                                       transition duration-150 active:scale-[.98]">
                                            Continuer
                                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 7l5 5m0 0l-5 5m5-5H6" />
                                            </svg>
                                        </button>
                                    </div>
                                <?php endif; ?>

                            </form>
                        <?php endif; ?>
                    </div><!-- /p-8 -->
                </div><!-- /card -->

                <!-- ── Footer ── -->
                <div class="mt-5 text-center">
                    <p class="text-xs text-zinc-400 dark:text-zinc-600 tabular-nums">
                        Étape <?= $displayStep ?> / <?= $total_steps ?>
                    </p>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>
<?php


$view->endSection();
$view->section('footer');
$view->inc('partials', 'footer.php', []);
$view->endSection();
$view->section('styles');
echo AssetHelper::styles([
    'styles:install.css',
]);
$view->endSection();
?>