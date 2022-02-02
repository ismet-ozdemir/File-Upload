<?php
/* $this->dbCreate('mydb');    ---> veritabanı oluşturma

 $scheme = array(      ---> tablo sütunları oluşturma ve özellik belirtme
    'id:increments',
    'image',
);
$this->tableCreate('files', $scheme);  ---> tablo oluşturma */
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <base href="<? $this->base_url; ?>">
    <meta charset="UTF-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Upload</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">
</head>

<body>
    <div class="container h-100" style="height: 600px;">
        <div class="row" style="height: 600px;">
            <form method="post" enctype="multipart/form-data" class="d-flex align-items-center">
                <div class="col-lg-12">
                    <input class="form-control" type="file" name="image">
                    <?= $_SESSION['csrf']['input']; ?>
                </div>
                <div class="col-lg-12">
                    <button type="submit" class="btn  btn-primary">Upload</button>
                </div>
            </form>
            <?php

            if (!empty($this->post['image'])) {
                $values = array(
                    'image'     => json_encode($this->post['image'])
                );
                $query = $this->insert('files', $values);

                if (isset($query)) {
                    $path = './upload/';
                    $u = $this->upload($this->post['image'], $path, true);
                    $this->print_pre($u);
                }
            }
            ?>
        </div>
    </div>
</body>

</html>