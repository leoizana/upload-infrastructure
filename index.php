<?php

require_once 'vendor/autoload.php';

use MicrosoftAzure\Storage\Blob\BlobRestProxy;
use MicrosoftAzure\Storage\Common\Exceptions\ServiceException;

$dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
$dotenv->load();
// --- Configuration de la base de données MySQL ---
// Dans une application réelle, ces informations seraient dans un fichier de configuration non versionné.
define('DB_HOST', $_ENV['BDD_HOST'] ?? '127.0.0.1');
define('DB_NAME', $_ENV['BDD_NAME']);
define('DB_USER', $_ENV['BDD_USER']);
define('DB_PASS', $_ENV['BDD_PASS']);
define('AZURE_CONNECTION_STRING', $_ENV['AZURE_CONNECTION_STRING']);
// Le nom du compte et la clé sont nécessaires pour générer les liens SAS dans liste.php
define('AZURE_ACCOUNT_NAME', $_ENV['AZURE_ACCOUNT_NAME']);
define('AZURE_ACCOUNT_KEY', $_ENV['AZURE_ACCOUNT_KEY']);
define('AZURE_CONTAINER_NAME', $_ENV['AZURE_CONTAINER_NAME'] ?: 'uploads');
define('DB_CHARSET', 'utf8mb4');
// ---------------------------------------------

/**
 * Connexion à la bdd (MySQL) et création de la table si elle n'existe pas.
 * @return PDO
 */
function initDatabase() {
    // Pour une connexion SSL, le DSN n'a pas besoin de changer.
    $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

    // Activer SSL seulement si la variable d'environnement est définie à 'true'
    if (filter_var($_ENV['DB_SSL_ENABLED'] ?? false, FILTER_VALIDATE_BOOLEAN)) {
        $ssl_ca_path = __DIR__ . '/DigiCertGlobalRootG2.crt.pem';
        if (!file_exists($ssl_ca_path)) {
            die("Erreur : Le fichier de certificat SSL ('DigiCertGlobalRootG2.crt.pem') est requis mais introuvable.");
        }
        $options[PDO::MYSQL_ATTR_SSL_CA] = $ssl_ca_path;
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false; // Important pour Azure
    }

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
        `url_fichier` VARCHAR(255) NULL,
        `taille` BIGINT NOT NULL,
        `type_mime` VARCHAR(100),
        `date_upload` DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;");

    return $db;
}
// Note : Si votre table 'fichiers' existe déjà, vous devrez peut-être exécuter cette commande SQL manuellement pour autoriser les URL nulles : ALTER TABLE fichiers MODIFY url_fichier VARCHAR(255) NULL;

/**
 * Initialise le client Azure Blob et crée le conteneur s'il n'existe pas.
 * @return BlobRestProxy
 */
function initAzureBlobService() {
    if (!AZURE_CONNECTION_STRING) {
        die("La variable d'environnement AZURE_CONNECTION_STRING n'est pas définie.");
    }
    $blobClient = BlobRestProxy::createBlobService(AZURE_CONNECTION_STRING);

    // Crée le conteneur s'il n'existe pas.
    // Par défaut, le conteneur sera privé.
    try {
        $blobClient->getContainerProperties(AZURE_CONTAINER_NAME);
    } catch (ServiceException $e) {
        // Le conteneur n'existe pas, on le crée.
        if ($e->getCode() === 404) {
            $blobClient->createContainer(AZURE_CONTAINER_NAME);
        } else {
            // Autre erreur
            die("Erreur Azure: " . $e->getMessage());
        }
    }

    return $blobClient;
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
 * @param mixed $taille
 * @param mixed $url_fichier
 * @param mixed $type_mime
 */
function enregistrerFichierBDD($db, $nom_fichier, $nom_original, $taille, $type_mime, $url_fichier) {
    $stmt = $db->prepare("INSERT INTO fichiers (nom_fichier, nom_original, url_fichier, taille, type_mime) 
                          VALUES (:nom_fichier, :nom_original, :url_fichier, :taille, :type_mime)");
    return $stmt->execute([
        ':nom_fichier' => $nom_fichier,
        ':nom_original' => $nom_original,
        ':url_fichier' => $url_fichier,
        ':taille' => $taille,
        ':type_mime' => $type_mime,
    ]);
}

/**
 * Traiter l'upload du fichier
 * @param PDO $db
 * @param BlobRestProxy $blobClient
 * @param array $fichier
 * @param string $name
 * @return array{message: string, success: bool}
 */
function traiterUpload($db, $blobClient, $fichier, $name) {
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

    $containerName = AZURE_CONTAINER_NAME;
    $fileContent = fopen($fichier['tmp_name'], "r");

    try {
        // Upload du blob
        $blobClient->createBlockBlob($containerName, $nom_fichier, $fileContent);
    } catch (ServiceException $e) {
        return ['success' => false, 'message' => "✗ Erreur lors de l'upload vers Azure : " . $e->getMessage()];
    }

    // On construit l'URL de base du blob. Le lien SAS sera généré à la volée dans liste.php
    $url_fichier = sprintf(
        'https://%s.blob.core.windows.net/%s/%s',
        AZURE_ACCOUNT_NAME,
        $containerName,
        $nom_fichier
    );

    enregistrerFichierBDD($db, $nom_fichier, $nom_original, $fichier['size'], $fichier['type'], $url_fichier);
    
    return ['success' => true, 'message' => "✓ Fichier uploadé avec succès sur Azure : " . htmlspecialchars($nom_original)];
}

$db = initDatabase();
$blobClient = initAzureBlobService();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier'])) {
    // On passe le client blob à la fonction de traitement
    $resultat = traiterUpload($db, $blobClient, $_FILES['fichier'], $_POST['name'] ?? '');

    // Si la requête est une requête AJAX (envoyée par fetch), on renvoie du JSON
    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest') {
        header('Content-Type: application/json');
        echo json_encode($resultat);
        exit();
    }

    // Sinon, on continue avec le rechargement de la page classique
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
        
        <!-- Le conteneur de message est toujours présent pour être ciblé par JS -->
        <div class="message <?php echo isset($message_type) ? $message_type : ''; ?>" style="<?php echo isset($message) ? 'display: block;' : 'display: none;'; ?>">
            <?php echo isset($message) ? $message : ''; ?>
        </div>

        <form action="index.php" method="post" enctype="multipart/form-data" id="upload-form">
            <div class="form-group">
                <label for="name">Nom du fichier (obligatoire) :</label>
                <input type="text" name="name" id="name" required>
            </div>

            <!-- Section pour la caméra -->
            <div class="form-group">
                <label>Source du fichier :</label>
                <button type="button" id="start-camera" class="camera-button">Utiliser la caméra</button>
            </div>

            <div id="camera-view" style="display:none; margin-bottom: 25px; text-align: center;">
                <video id="video" width="100%" autoplay playsinline style="border-radius: 5px; background: #333;"></video>
                <button type="button" id="click-photo" class="camera-button capture">Capturer la photo</button>
                <canvas id="canvas" style="display:none;"></canvas>
                <div id="photo-preview-container" style="display:none; margin-top: 15px;">
                    <label style="text-align: left;">Aperçu :</label>
                    <img id="photo-preview" style="max-width: 100%; border-radius: 5px; margin-top: 10px; border: 1px solid #ddd;">
                </div>
            </div>
            <!-- Fin section caméra -->

            <div class="form-group">
                <label for="fichier">Choisissez un fichier à envoyer :</label>
                <input type="file" name="fichier" id="fichier">
            </div>
            
            <button type="submit">Envoyer le fichier</button>
        </form>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', () => {
            const startCameraButton = document.getElementById('start-camera');
            const cameraView = document.getElementById('camera-view');
            const video = document.getElementById('video');
            const clickPhotoButton = document.getElementById('click-photo');
            const canvas = document.getElementById('canvas');
            const photoPreviewContainer = document.getElementById('photo-preview-container');
            const photoPreview = document.getElementById('photo-preview');
            const fileInput = document.getElementById('fichier');
            const uploadForm = document.getElementById('upload-form');
            const messageDiv = document.querySelector('.message');
            let photoTaken = false;
            let stream = null;

            startCameraButton.addEventListener('click', async () => {
                try {
                    stream = await navigator.mediaDevices.getUserMedia({ video: true, audio: false });
                    video.srcObject = stream;
                    cameraView.style.display = 'block';
                    startCameraButton.style.display = 'none';
                    fileInput.value = ''; // On réinitialise le champ fichier si l'utilisateur choisit la caméra
                    photoTaken = false;
                    photoPreviewContainer.style.display = 'none';
                } catch (err) {
                    console.error("Erreur d'accès à la caméra: ", err);
                    alert("Impossible d'accéder à la caméra. Assurez-vous d'avoir donné la permission et que votre navigateur est compatible (HTTPS est souvent requis).");
                }
            });

            clickPhotoButton.addEventListener('click', () => {
                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
                
                const dataUrl = canvas.toDataURL('image/jpeg');
                photoPreview.src = dataUrl;
                photoPreviewContainer.style.display = 'block';
                photoTaken = true;
                fileInput.value = ''; // On s'assure que le champ fichier est vide

                // Arrêter la caméra après la photo
                if (stream) {
                    stream.getTracks().forEach(track => track.stop());
                }
                cameraView.style.display = 'none';
                startCameraButton.style.display = 'block';
            });

            // Si l'utilisateur choisit un fichier, on cache la preview de la photo
            fileInput.addEventListener('change', () => {
                if (fileInput.files.length > 0) {
                    photoTaken = false;
                    photoPreviewContainer.style.display = 'none';
                }
            });

            uploadForm.addEventListener('submit', function(event) {
                event.preventDefault(); // On empêche la soumission classique

                if (!photoTaken && fileInput.files.length === 0) {
                    alert("Veuillez choisir un fichier ou prendre une photo.");
                    return;
                }

                const formData = new FormData(uploadForm);

                if (photoTaken) {
                    canvas.toBlob(function(blob) {
                        // On ajoute le fichier au FormData. Le nom du champ doit être 'fichier'
                        let fileName = formData.get('name');
                        if (!fileName.toLowerCase().endsWith('.jpg') && !fileName.toLowerCase().endsWith('.jpeg')) {
                            fileName += '.jpg';
                        }
                        formData.set('fichier', blob, fileName);
                        
                        submitFormData(formData);
                    }, 'image/jpeg');
                } else {
                    // Si un fichier a été sélectionné, on envoie directement
                    submitFormData(formData);
                }
            });

            function submitFormData(formData) {
                messageDiv.style.display = 'none'; // Cacher l'ancien message
                
                fetch('index.php', {
                    method: 'POST',
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest' // Pour que le PHP sache que c'est une requête AJAX
                    },
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    // Affiche le message de retour
                    messageDiv.textContent = data.message;
                    messageDiv.className = `message ${data.success ? 'success' : 'error'}`;
                    messageDiv.style.display = 'block';

                    if (data.success) {
                        uploadForm.reset();
                        photoPreviewContainer.style.display = 'none';
                        photoTaken = false;
                    }
                })
                .catch(error => {
                    console.error('Erreur:', error);
                    messageDiv.textContent = 'Une erreur inattendue est survenue lors de l\'envoi.';
                    messageDiv.className = 'message error';
                    messageDiv.style.display = 'block';
                });
            }
        });
    </script>

    <style>
        .camera-button {
            width: 100%;
            padding: 12px;
            background: #f8f9ff;
            border: 2px dashed #667eea;
            color: #555;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.3s;
        }
        .camera-button:hover {
            border-color: #764ba2;
            background: #f0f1ff;
        }
        .camera-button.capture {
            margin-top: 10px;
        }
    </style>

</body>
</html>
</body>
</html>