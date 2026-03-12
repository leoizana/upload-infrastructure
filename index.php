<?php
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['fichier'])) {
    $dossier_upload = 'uploads/';
    
    // Créer le dossier s'il n'existe pas
    if (!is_dir($dossier_upload)) {
        mkdir($dossier_upload, 0777, true);
    }
    
    $fichier = $_FILES['fichier'];
    $nom_fichier = basename($fichier['name']);
    $chemin_destination = $dossier_upload . $nom_fichier;
    
    if ($fichier['error'] === 0) {
        if (move_uploaded_file($fichier['tmp_name'], $chemin_destination)) {
            $message = "✓ Fichier uploadé avec succès : " . htmlspecialchars($nom_fichier);
            $message_type = "success";
        } else {
            $message = "✗ Erreur lors de l'upload du fichier.";
            $message_type = "error";
        }
    } else {
        $message = "✗ Erreur : " . $fichier['error'];
        $message_type = "error";
    }
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
    </style>
</head>
<body>
    <div class="container">
        <h1>📤 Upload de fichier</h1>
        
        <?php if (isset($message)): ?>
            <div class="message <?php echo $message_type; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <form action="" method="post" enctype="multipart/form-data">
            <div class="form-group">
                <label for="fichier">Choisissez un fichier à envoyer :</label>
                <input type="file" name="fichier" id="fichier" required>
            </div>
            
            <button type="submit">Envoyer le fichier</button>
        </form>
    </div>
</body>
</html>