<?php

// --- Configuration de la base de données MySQL ---
// Dans une application réelle, ces informations seraient dans un fichier de configuration non versionné.
define('DB_HOST', getenv('BDD_HOST') ?: 'localhost');
define('DB_NAME', getenv('BDD_NAME') ?: getenv('DB_NAME')); // Remplacez par le nom de votre base de données
define('DB_USER', getenv('BDD_USER') ?: getenv('DB_USER'));      // Remplacez par votre nom d'utilisateur
define('DB_PASS', getenv('BDD_PASS') ?: getenv('DB_PASS'));        // Remplacez par votre mot de passe
define('DB_CHARSET', 'utf8mb4');
// ---------------------------------------------

/**
 * Connexion à la bdd (MySQL)
 * @return PDO
 */
function initDatabase() {
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];
    try {
        return new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $e) {
        die("Erreur de connexion à la base de données : " . $e->getMessage());
    }
}

/**
 * Formate la taille d'un fichier en une chaîne lisible par l'homme.
 * @param int $bytes
 * @param int $precision
 * @return string
 */
function formatBytes($bytes, $precision = 2) { 
    $units = ['B', 'KB', 'MB', 'GB', 'TB']; 

    $bytes = max($bytes, 0); 
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024)); 
    $pow = min($pow, count($units) - 1); 

    $bytes /= (1 << (10 * $pow)); 

    return round($bytes, $precision) . ' ' . $units[$pow]; 
} 

$db = initDatabase();
$stmt = $db->query("SELECT id, nom_fichier, nom_original, url_fichier, taille, type_mime, date_upload FROM fichiers ORDER BY date_upload DESC");
$fichiers = $stmt->fetchAll(PDO::FETCH_ASSOC);

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Liste des fichiers</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            justify-content: center;
            align-items: flex-start;
            padding: 20px;
        }
        
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            width: 100%;
            max-width: 900px;
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
            font-size: 28px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            margin-bottom: 20px;
        }
        
        th, td {
            padding: 12px 15px;
            text-align: left;
            border-bottom: 1px solid #ddd;
        }
        
        th {
            background-color: #f8f9ff;
            color: #555;
            font-weight: bold;
        }
        
        tr:hover {
            background-color: #f0f1ff;
        }
        
        a {
            color: #667eea;
            text-decoration: none;
        }
        
        a:hover {
            text-decoration: underline;
            color: #764ba2;
        }
        
        .no-files {
            text-align: center;
            padding: 20px;
            color: #777;
        }

        .back-link {
            display: inline-block;
            padding: 10px 15px;
            background: #f8f9ff;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-weight: bold;
        }

        .header-nav {
            display: flex;
            justify-content: center;
            gap: 20px;
            margin-bottom: 25px;
            padding-bottom: 20px;
            border-bottom: 1px solid #eee;
        }

        .header-nav a {
            text-decoration: none;
            color: #555;
            font-weight: bold;
            padding: 8px 15px;
            border-radius: 5px;
            transition: background-color 0.3s, color 0.3s;
        }

        .header-nav a.active, .header-nav a:hover {
            background-color: #667eea;
            color: white;
            text-decoration: none;
        }
    </style>
</head>
<body>
    <div class="container">
        <nav class="header-nav">
            <a href="index.php">📤 Upload</a>
            <a href="liste.php" class="active">📁 Liste des fichiers</a>
        </nav>
        <h1>Liste des fichiers</h1>

        <?php if (empty($fichiers)): ?>
            <p class="no-files">Aucun fichier n'a été uploadé pour le moment.</p>
        <?php else: ?>
            <table>
                <thead>
                    <tr>
                        <th>Nom du fichier</th>
                        <th>Nom original</th>
                        <th>Taille</th>
                        <th>Date d'upload</th>
                        <th>Lien</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($fichiers as $fichier): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($fichier['nom_fichier']); ?></td>
                            <td><?php echo htmlspecialchars($fichier['nom_original']); ?></td>
                            <td><?php echo formatBytes($fichier['taille']); ?></td>
                            <td><?php echo (new DateTime($fichier['date_upload']))->format('d/m/Y H:i'); ?></td>
                            <td><a href="<?php echo htmlspecialchars($fichier['url_fichier']); ?>" download>Télécharger</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</body>
</html>