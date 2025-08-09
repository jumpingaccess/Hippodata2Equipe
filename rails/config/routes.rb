# config/routes.rb

Rails.application.routes.draw do
  get 'import/index', to: 'import#index', as: 'import_index'
  post 'import/fetch_event_info', to: 'import#fetch_event_info', as: 'fetch_event_info'
  post 'import/get_imported_status', to: 'import#get_imported_status', as: 'get_imported_status'
  post 'import/import_selected', to: 'import#import_selected', as: 'import_selected'
  post 'import/send_batch_to_equipe', to: 'import#send_batch_to_equipe', as: 'send_batch_to_equipe'

  root 'import#index'
end