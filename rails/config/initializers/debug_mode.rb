# config/initializers/debug_mode.rb
Rails.application.config.debug_mode = ENV['DEBUG'] == '1'