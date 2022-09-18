<!doctype html>
<html lang="pl">

<head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no, IE=edge">

    <!-- TSR CSS AND JS -->
    <link rel="stylesheet" href="https://timonix.pl/files/css/timonix_styles_rezult.css">
    <script src="https://code.jquery.com/jquery-3.5.1.slim.min.js" integrity="sha384-DfXdz2htPH0lsSSs5nCTpuj/zy4C+OGpamoFVy38MVBnE+IbbVYUew+OrCXaRkfj" crossorigin="anonymous"></script>
    <script src="https://timonix.pl/files/js/timonix_styles_rezult.js"></script>
    <title><?php echo $this->title ?></title>
</head>

<body>
    <header class="">
        <nav class="tsr-nav-menu tsr-p-5px tsr-nav-menu tsr-menu-background">
            <section class="col-ms70 tsr-menu-hiden">
                <section class="col-ms20 tsr-height-100 tsr-height-50px tsr fs-150">
                    <span class="fs-150">Atom</span>
                </section>
                <section class="col-ms80 ">
                    <ol class="menu">
                        <li><a class="nav-link" href="/Atom/public/">Home</a></li>
                        
                        <li>
                            <a class="nav-link" href="/Atom/public/contact">Contact</a>
                        </li>
                        
                        <li>
                            <a class="nav-link" href="/Atom/public/about">About</a>
                        </li>
                    </ol>
                </section>
            </section>
            <section class="col-ms30 tsr-width-50-2 tsr-height-40px-3 tsr-float-right-3">
                <?php

                use Atom\core\Atom;

                if (Atom::isGuest()) : ?>
                    <ol class="menu">
                         <li class="nav-item active">
                            <a class="nav-link" href="/Atom/public/login">Login</a>
                        </li>
                        <li class="nav-item active">
                            <a class="nav-link" href="/Atom/public/register">Register</a>
                        </li>
                    </ol>
                <?php else : ?>
                    <ol class="menu">
                         <li class="nav-item active">
                            <a class="nav-link" href="/Atom/public/profile">
                                Profile
                            </a>
                        </li>
                        <li class="nav-item active">
                            <a class="nav-link" href="/Atom/public/logout">
                                <?php echo Atom::$app->user->getDisplayName() ?> (Logout)
                            </a>
                        </li>
                    </ol>
                <?php endif; ?>
            </section>

            <div class="tsr-button-menu-left" onclick="myFunction(this)">
                <div class="bar1"></div>
                <div class="bar2"></div>
                <div class="bar3"></div>
            </div>

            <div class="tsr-button-menu-mobile" onclick="myFunction(this)">
                <div class="bar1"></div>
                <div class="bar2"></div>
                <div class="bar3"></div>
            </div>

            <div class="tsr tsr-display-none tsr-display-block-3 tsr-width-50-3 tsr-width-50px-2 tsr-height-40px tsr-float-right tsr-ma">
                <img src="https://localhost/bm/pliki/logo/logo_bm_black_2_100_100.png" alt="logo" class="tsr">
            </div>

        </nav>
    </header>

    <main class="tsr container">
        <?php if (Atom::$app->session->getFlash('success')) : ?>
            <div class="alert alert-success">
                <p><?php echo Atom::$app->session->getFlash('success') ?></p>
            </div>
        <?php endif; ?>
        {{content}}
    </main>

</body>

</html>