## Database settings
$wgDBtype = "mysql";
$wgDBserver = getenv("STACKHERO_MYSQL_HOST")
$wgDBname = "root"
$wgDBuser = "root"
$wgDBpassword = getenv("STACKHERO_MYSQL_ROOT_PASSWORD")

wfLoadExtension('SemanticMediaWiki');
enableSemantics('safe-cities-wiki.heroku.app');
