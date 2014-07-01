set :application, 'wp-cdn-rewrite'
set :repo_url, "git@github.com:voceconnect/cdn-rewrite.git"

set :scm, 'git-to-svn'
set :type, 'plugin'

set :svn_repository, "http://plugins.svn.wordpress.org/#{fetch(:application)}/"
set :svn_deploy_to, "trunk"

set :build_folders, (
  fetch(:build_folders) << %w{
  	config
  }
).flatten