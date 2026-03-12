<?php

// --- Configuration de la base de données MySQL ---
// Dans une application réelle, ces informations seraient dans un fichier de configuration non versionné.
define('DB_HOST', 'localhost');
define('DB_NAME', 'upload_db'); // Remplacez par le nom de votre base de données
define('DB_USER', 'root');      // Remplacez par votre nom d'utilisateur
define('DB_PASS', '');        // Remplacez par votre mot de passe
define('DB_CHARSET', 'utf8mb4');
// ---------------------------------------------

/**
 * Connexion à la bdd (MySQL) et création de la table si elle n'existe pas.
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
        $db = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (\PDOException $e) {
        // En production, il serait préférable de logger l'erreur plutôt que de l'afficher.
        die("Erreur de connexion à la base de données : " . $e->getMessage());
    }

    // Le script de création de table est maintenant adapté pour MySQL.
    $db->exec("CREATE TABLE IF NOT EXISTS `fichiers` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `nom_fichier` VARCHAR(255) NOT NULL,
        `nom_original` VARCHAR(255) NOT NULL,
        `url_fichier` VARCHAR(255) NOT NULL,
        `taille` BIGINT NOT NULL,
        `type_mime` VARCHAR(100),
        `date_upload` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    return $db;
}

/**
 * Créer le dossier d'upload s'il n'existe pas
 * @param mixed $dossier
 * @return void
 */
function creerDossierUpload($dossier) {
    if (!is_dir($dossier)) {
        mkdir($dossier, 0777, true);
    }
}

/**
 * Générer un nom de fichier unique pour éviter les conflits
 * @param mixed $nom_original
 * @return string
 */
function genererNomFichierUnique($nom_original) {
    $extension = pathinfo($nom_original, PATHINFO_EXTENSION);
    $nom_sans_ext = pathinfo($nom_original, PATHINFO_FILENAME);
    return $nom_sans_ext . '_' . time() . '.' . $extension;
}

/**
 * Enregistrer les informations du fichier dans la base de données
 * @param mixed $db
 * @param mixed $nom_fichier
 * @param mixed $nom_original
 * @param mixed $url_fichier
 * @param mixed $taille
 * @param mixed $type_mime
 */
function enregistrerFichierBDD($db, $nom_fichier, $nom_original, $url_fichier, $taille, $type_mime) {
    $stmt = $db->prepare("INSERT INTO fichiers (nom_fichier, nom_original, url_fichier, taille, type_mime) 
                          VALUES (:nom_fichier, :nom_original, :url_fichier, :taille, :type_mime)");
    return $stmt->execute([
        ':nom_fichier' => $nom_fichier,
        ':nom_original' => $nom_original,
        ':url_fichier' => $url_fichier,
        ':taille' => $taille,
        ':type_mime' => $type_mime
    ]);
}

/**
 * Traiter l'upload du fichier
 * @param mixed $db
 * @param mixed $fichier
 * @param mixed $dossier_upload
 * @return array{message: string, success: bool}
 */
function traiterUpload($db, $fichier, $dossier_upload, $name) {
    if ($fichier['error'] !== 0) {
        return ['success' => false, 'message' => "✗ Erreur : " . $fichier['error']];
    }
    
    $nom_original = basename($fichier['name']);
    $extension = pathinfo($nom_original, PATHINFO_EXTENSION);
    $name = trim($name);
    
    if (!empty($name)) {
        if (!empty($extension) && pathinfo($name, PATHINFO_EXTENSION) !== $extension) {
            $nom_fichier = $name . '.' . $extension;
        } else {
            $nom_fichier = $name;
        }
    } else {
        $nom_fichier = genererNomFichierUnique($nom_original);
    }
    $chemin_destination = $dossier_upload . $nom_fichier;
    
    if (!move_uploaded_file($fichier['tmp_name'], $chemin_destination)) {
        return ['success' => false, 'message' => "✗ Erreur lors de l'upload du fichier."];
    }
    
    enregistrerFichierBDD($db, $nom_fichier, $nom_original, $chemin_destination, $fichier['size'], $fichier['type']);
    
    return ['success' => true, 'message' => "✓ Fichier uploadé avec succès : " . htmlspecialchars($nom_original)];
}

$db = initDatabase();
$dossier_upload = 'uploads/';
creerDossierUpload($dossier_upload);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier'])) {
    $resultat = traiterUpload($db, $_FILES['fichier'], $dossier_upload, $_POST['name'] ?? '');
    $message = $resultat['message'];
    $message_type = $resultat['success'] ? 'success' : 'error';
}

?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload de fichier</title>
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
            align-items: center;
            padding: 20px;
        }
        
        .container {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.2);
            max-width: 500px;
            width: 100%;
        }
        
        h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
            font-size: 28px;
        }
        
        .form-group {
            margin-bottom: 25px;
        }
        
        label {
            display: block;
            margin-bottom: 10px;
            color: #555;
            font-weight: bold;
        }
        
        input[type="text"] {
            width: 100%;
            padding: 12px;
            border: 2px solid #667eea;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        input[type="text"]:focus {
            outline: none;
            border-color: #764ba2;
            box-shadow: 0 0 0 3px rgba(118, 75, 162, 0.1);
        }
        
        input[type="file"] {
            width: 100%;
            padding: 12px;
            border: 2px dashed #667eea;
            border-radius: 5px;
            background: #f8f9ff;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        input[type="file"]:hover {
            border-color: #764ba2;
            background: #f0f1ff;
        }
        
        button {
            width: 100%;
            padding: 15px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 16px;
            font-weight: bold;
            cursor: pointer;
            transition: transform 0.2s;
        }
        
        button:hover {
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }
        
        button:active {
            transform: translateY(0);
        }
        
        .message {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            text-align: center;
            font-weight: bold;
        }
        
        .message.success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        
        .message.error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
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
            <a href="index.php" class="active">📤 Upload</a>
            <a href="liste.php">📁 Liste des fichiers</a>
        </nav>
        <h1>Upload de Fichier</h1>
        
        <?php if (isset($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>

        <form action="" method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="fichier">Nom de votre fichier :</label>
                <input type="text" name="name" id="name" required>
            </div>
            <div class="form-group">
                <label for="fichier">Choisissez un fichier à envoyer :</label>
                <input type="file" name="fichier" id="fichier" required>
            </div>
            
            <button type="submit">Envoyer le fichier</button>
        </form>
    </div>
</body>
</html>