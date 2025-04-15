<h1 align="center">SuluPhpcrMigrationBundle</h1>

<p align="center">
    <a href="https://sulu.io/" target="_blank">
        <img width="30%" src="https://sulu.io/uploads/media/800x/00/230-Official%20Bundle%20Seal.svg?v=2-6&inline=1" alt="Official Sulu Bundle Badge">
    </a>
</p>
### Upgrading Data from Sulu 2.6 to Sulu 3.0

The upgrade from Sulu 2.6 to Sulu 3.0 is a major upgrade and will require some migration steps.

Due to the fact that in Sulu 3.0 the SuluArticleBundle is merged into the core Sulu package, before updating the 
sulu/sulu dependency, you have to update the sulu/article-bundle dependency to the latest version. 

```shell
    composer update sulu/article-bundle:"^2.6.7"
```

After that you have to execute the latest PhpCr migrations. 

```shell
    php bin/adminconsole phpcr:migrations:migrate
```
Ensure that the [Version202407111600](https://github.com/sulu/SuluArticleBundle/blob/2.6/Resources/phpcr-migrations/Version202407111600.php) migration is executed. This migration is required to migrate the old article
structure to the new one. After that you can update the sulu/sulu dependency to the 3.0 version and install
the SuluPhpcrMigrationBundle.

```shell
    composer remove sulu/article-bundle
    composer remove elasticsearch/elasticsearch
    composer update sulu/sulu:"^3.0"
    composer require sulu/phpcr-migration-bundle
```

Disable the old SuluArticleBundle and activate the new Sulu 3.0 bundles in `config/bundles.php`:

```diff

return [
    // ...
-        Sulu\Bundle\SnippetBundle\SuluSnippetBundle::class => ['all' => true],
-        Sulu\Bundle\ArticleBundle\SuluArticleBundle::class => ['all' => true],
-        ONGR\ElasticsearchBundle\ONGRElasticsearchBundle::class => ['all' => true],
-        Sulu\Bundle\SnippetBundle\SuluSnippetBundle::class => ['all' => true],

+        Sulu\Bundle\PhpcrMigrationBundle\SuluPhpcrMigrationBundle::class => ['all' => true],
+        Sulu\Content\Infrastructure\Symfony\HttpKernel\SuluContentBundle::class => ['all' => true],
+        Sulu\Messenger\Infrastructure\Symfony\HttpKernel\SuluMessengerBundle::class => ['all' => true],
+        Sulu\Article\Infrastructure\Symfony\HttpKernel\SuluArticleBundle::class => ['all' => true],
+        Sulu\Snippet\Infrastructure\Symfony\HttpKernel\SuluSnippetBundle::class => ['all' => true],
+        Sulu\Page\Infrastructure\Symfony\HttpKernel\SuluPageBundle::class => ['all' => true],
```

Configure the SuluPhpcrMigrationBundle in `config/packages/sulu_phpcr_migration.yaml`:

> If you are currently using Jackrabbit, use the "jackrabbit://" based DSN string.
> After the upgrade, Apache Jackrabbit is no longer used by Sulu’s new content storage and can be removed from
> your projects in most situations.

```yaml
sulu_phpcr_migration:
    # dbal://<dbalConnection>?workspace=<workspaceName>
    # jackrabbit://<user>:<password>@<host>:<port>/server?workspace=<workspaceName>
    #    DSN: "dbal://default?workspace=%env(PHPCR_WORKSPACE)%"
    #    DSN: "jackrabbit://admin:admin@127.0.0.1:8080/server?workspace=%env(PHPCR_WORKSPACE)%"
    DSN: "dbal://default?workspace=%env(PHPCR_WORKSPACE)%"
    target:
        dbal:
            connection: default

```

The new content structure used in Sulu 3.0 requires that all the `resource_locators` or `route` properties must be 
renamed to `url` in your templates.

```diff
-        <property name="routePath" type="route">
+        <property name="url" type="route">
            <meta>
                <title lang="en">Resourcelocator</title>
                <title lang="de">Adresse</title>
            </meta>

            <tag name="sulu_article.article_route"/>
        </property>
```

Additionally, the controller in the page/article templates has to be adjusted use the new controller 
from the SuluContentBundle. Be aware that also your custom controllers have to be modified to extend from the new one.

```diff
-        <controller class="Sulu\Bundle\ArticleBundle\Controller\ArticleController"/>
-        <controller>Sulu\Bundle\WebsiteBundle\Controller\DefaultController::indexAction</controller>
-        <controller>Sulu\Content\UserInterface\Controller\Website\ContentController::indexAction</controller>
```

Now you have to update the database schema. You can use the following command to do that:

```shell
    php bin/adminconsole doctrine:schema:update --force
```

After that you can run the following command to update the content structure:

```shell
    php bin/adminconsole sulu:phpcr-migration:migrate
```
In case of some errors on customized code, you can try to fix it and rerun the command. The migration command can be
rerun, the existing already migrated content will be overwritten and not duplicated.
If everything is done and the migration is successful, you can log in to the Sulu admin interface, set the permissions
for the articles and snippets and check if everything is working as expected.

MORE INFO HERE: https://github.com/sulu/sulu/blob/3.0/UPGRADE.md#300

## ❤️&nbsp; Support and Contributions

The Sulu content management system is a **community-driven open source project** backed by various partner companies.
We are committed to a fully transparent development process and **highly appreciate any contributions**.

In case you have questions, we are happy to welcome you in our official [Slack channel](https://sulu.io/services-and-support).
If you found a bug or miss a specific feature, feel free to **file a new issue** with a respective title and description
on the [sulu/SuluPHPCRMigrationBundle](https://github.com/sulu/SuluPHPCRMigrationBundle) repository.


## 📘&nbsp; License

The Sulu content management system is released under the under terms of the [MIT License](LICENSE).
